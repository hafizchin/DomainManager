<?php

namespace DomainManager\Api;

use DomainManager\Entity\DomainSiteMapping;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Model\ViewModel;
use Laminas\View\Renderer\PhpRenderer;

class DomainMapper
{
    private $event;
    /** @var \Doctrine\ORM\EntityManager */
    private $entityManager;
    private $routes;
    private $siteIndicator = '/s/';

    private $controller;

    /**
     * ignore these routes as they are not domain specific
     */
    private $ignoredRoutes = [
        'admin',
        'api',
        'api-context',
        'install',
        'migrate',
        'maintenance',
        'login',
        'logout',
        'create-password',
        'forgot-password',
        // Compatibility with modules that have a route on root.
        // Module Custom Ontology.
        'ns',
        // Module OAI-PMH Repository.
        'oai-pmh',
    ];

    private $uri;
    private $scheme;
    private $url;
    private $query;
    /** @var \Laminas\Router\Http\TreeRouteStack $router */
    private $router;
    private $domain;
    private $siteSlug;
    private $siteId;
    private $redirectUrl;
    private $defaultPage;
    private $defaultPageUrl;

    private $errors;

    private function initRoutingVariables()
    {
        $this->uri = $this->event->getRequest()->getUri();
        $this->scheme = $this->uri->getScheme();
        $this->url = $this->uri->getPath();
        $this->query = $this->uri->getQuery();
        $this->router = $this->event->getRouter();
        $this->domain = $this->uri->getHost();
        $this->siteSlug = $this->getSiteSlug();
        $this->siteId = $this->getSiteId();
        $this->redirectUrl = preg_replace("#{$this->siteIndicator}|{$this->siteSlug}#", '', $this->url);
        $this->defaultPage = $this->getDefaultPage();
    }

    private function isPluginConfigured()
    {
        $domain = $this->entityManager->createQueryBuilder()
            ->select('m.domain')
            ->from('DomainManager\Entity\DomainSiteMapping', 'm')
            ->leftJoin('Omeka\Entity\Site', 's', \Doctrine\ORM\Query\Expr\Join::WITH, 'm.site_id = s.id')
            ->where('m.domain = ?1')
            ->orWhere("s.slug = ?2")
            ->setParameter(1, $this->domain)
            ->setParameter(2, $this->siteSlug)
            ->getQuery()
            ->getArrayResult();
        return count($domain) > 0 ? !is_null($domain[0]['domain']) : false;
    }

    private function isIgnoredRoute()
    {
        $matches = [];
        preg_match('#(' . implode('|', $this->ignoredRoutes) . ')#', $this->redirectUrl, $matches);
        return (bool) count($matches);
    }

    private function isSitePrivate()
    {
        $isSitePrivate = $this->entityManager->createQueryBuilder()
            ->select('s.isPublic')
            ->from('Omeka\Entity\Site', 's')
            ->where('s.id = ?1')
            ->setParameter(1, $this->siteId)
            ->getQuery()
            ->getArrayResult();

        return count($isSitePrivate) > 0 ? (bool) $isSitePrivate[0]['isPublic'] : null;
    }

    private function isIndexPage()
    {
        return $this->url == "/";
    }

    private function isDefaultPageRoute()
    {
        return ltrim($this->url, '/') == $this->defaultPageRoute();
    }

    private function hasDomain()
    {
        return !is_null($this->siteSlug);
    }

    private function defaultPageRoute()
    {
        return ltrim("{$this->siteIndicator}{$this->siteSlug}", '/');
    }

    /**
     * @var \Laminas\Router\PriorityList $routes
     * @var \Laminas\Router\RouteInterface $route
     * 
     */
    private function routeTemplate($routes)
    {
        $routeKey = "site";
        
        /**
         * default route options
         */
        $routeType = \Laminas\Router\Http\Literal::class;
        $routePath = '/';

        if (empty($this->defaultPage)) {
            /**
             * set the default route to browse item
             */
            $rootRouteDefaults = [
                '__NAMESPACE__' => 'Omeka\Controller\Site',
                '__SITE__' => true,
                'controller' => 'Item',
                'action' => 'browse',
                'site-slug' => $this->siteSlug,
                'page-slug' => $this->defaultPage
            ];
            $defaultPageRouteDefaults = [
                'controller' => 'Item',
                'action' => 'browse',
                'site-slug' => $this->siteSlug,
                'page-slug' => $this->defaultPage
            ];
        } else {
            /**
             * set the default route to the default page
             */
            $rootRouteDefaults = [
                '__NAMESPACE__' => 'Omeka\Controller\Site',
                '__SITE__' => true,
                'controller' => 'Page',
                'action' => 'show',
                'site-slug' => $this->siteSlug,
                'page-slug' => $this->defaultPage
            ];
            $defaultPageRouteDefaults = [
                'controller' => 'Page',
                'action' => 'show',
                'site-slug' => $this->siteSlug,
                'page-slug' => $this->defaultPage
            ];        
        }

        $controller = strtolower($rootRouteDefaults["controller"]);
        $action     = strtolower($rootRouteDefaults["page-slug"] ?: $rootRouteDefaults["action"]);
        $this->defaultPageUrl =  $controller . DIRECTORY_SEPARATOR . $action;

        /**
         * the controller must be atleast 3 characters long so we don't match the site route ("/s/")
         */
        $controllerContraints = '([a-zA-Z][a-zA-Z0-9_-]*){3,}';

        /**
         * hopefully omeka-s' routing won't change
         * this is a modified version of omeka-s' original route in application/config/routes.config.php
         */
        $mappedRoutes = [
            $routeKey => [
                'type' => $routeType,
                'options' => [
                    'route' => $routePath,
                    'defaults' => $rootRouteDefaults,
                ],
                'may_terminate' => true,
                'child_routes' => [
                    /*
                     * this is the default page route (when url = /s/[SITE_SLUG])
                     */
                    'default-page' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => $this->defaultPageRoute(),
                            'defaults' => $defaultPageRouteDefaults,
                        ],
                    ],
                    'resource' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => ':controller[/:action]',
                            'defaults' => [
                                'controller' => 'Item',
                                'action' => 'browse',
                                'site-slug' => $this->siteSlug,
                            ],
                            'constraints' => [
                                'controller' => $controllerContraints,
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                            ],
                        ],
                    ],
                    'resource-id' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => ':controller/:id[/:action]',
                            'defaults' => [
                                'controller' => 'Item',
                                'action' => 'show',
                                'site-slug' => $this->siteSlug,
                            ],
                            'constraints' => [
                                'controller' => $controllerContraints,
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'id' => '\d+',
                            ],
                        ],
                    ],
                    'item-set' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => 'item-set[/:item-set-id]',
                            'defaults' => [
                                'controller' => 'Item',
                                'action' => 'browse',
                                'site-slug' => $this->siteSlug,
                            ],
                            'constraints' => [
                                'item-set-id' => '\d+',
                            ],
                        ],
                    ],
                    'page-browse' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => 'page',
                            'defaults' => [
                                'controller' => 'Page',
                                'action' => 'browse',
                                'site-slug' => $this->siteSlug,
                            ],
                        ],
                    ],
                    'page' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => 'page/:page-slug',
                            'defaults' => [
                                'controller' => 'Page',
                                'action' => 'show',
                                'site-slug' => $this->siteSlug,
                            ],
                        ],
                    ],
                    'cross-site-search' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/cross-site-search[/:action]',
                            'defaults' => [
                                'controller' => 'CrossSiteSearch',
                                'action' => 'index',
                                'site-slug' => $this->siteSlug,
                            ],
                            'constraints' => [
                                'action' => '[a-zA-Z0-9_-]+',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        /**
         * static routes (defined by the config files)
         */
        foreach ($this->event->getApplication()->getServiceManager()->get("config")["router"]["routes"] as $mainRouteKey => $mainRouteArray) {
            if (in_array($mainRouteKey, ["site"])) {
                foreach ($mainRouteArray["child_routes"] as $childRouteKey => $childRouteArray) {
                    if (isset($mappedRoutes[$routeKey]["child_routes"][$childRouteKey])) {
                        continue;
                    }
                    
                    $childRouteArray["options"]["route"] = substr($childRouteArray["options"]["route"], 1);

                    /**
                     * we will assume that the default controller is called Index
                     */
                    if (!isset($childRouteArray["options"]["defaults"]["controller"])) {
                        $childRouteArray["options"]["defaults"]["controller"] = "Index";
                    }
                    
                    $childRouteArray["options"]["defaults"]["site-slug"]  = $this->siteSlug;

                    if (isset($childRouteArray["options"]["constraints"]) && stripos($childRouteArray["options"]["route"], ":controller") !== false) {
                        $childRouteArray["options"]["constraints"]["controller"] = $controllerContraints;
                    }

                    $mappedRoutes[$routeKey]["child_routes"][$childRouteKey] = $childRouteArray;
                }
            }
        }

        $ignoredRoutes = array_merge(['top', 'site'], $this->ignoredRoutes);

        /**
         * dynamically created routes
         * @var \Laminas\Router\PriorityList $routes
         * @var \Laminas\Router\RouteInterface $route
         * 
         */
        foreach ($routes as $routeName => $route) {
            if (in_array($routeName, $ignoredRoutes)) {
                continue;
            }

            /**
             * The original route config is not available directly. So the cast
             * to array allows to access to it: protected keys start with "0*0".
             */
            $routeArray = [];
            foreach ((array) $route as $key => $value) {
                $routeArray[substr($key, 3)] = $value;
            }

            /**
             * Build the route schema.
             */
            $routePath = '';
            if (isset($routeArray['parts'])) {
                /**
                 * The method assemble() is not available, because the site slug 
                 * is missing.
                 */
                foreach ($routeArray['parts'] as $part) {
                    list($type, $path) = $part;
                    
                    /** 
                     * The module Scripto use another route format, at top
                     * level, but with optional site slug.
                     */
                    if ($type === 'optional') {
                        $optionalParts = $path;
                        foreach ($optionalParts as $optionalPart) {
                            list($type, $path) = $optionalPart;
                            if (!in_array($path, [$this->siteIndicator, 'site-slug'])) {
                                if ($type == 'parameter') {
                                    $path = ':' . $path;
                                }
                                $routePath .= $path;
                            }
                        }
                    } else {
                        if (!in_array($path, [$this->siteIndicator, 'site-slug'])) {
                            if ($type == 'parameter') {
                                $path = ':' . $path;
                            }
                            $routePath .= $path;
                        }
                    }
                }
            }

            if (strlen($routePath)) {
                $newRoute = [
                    'type' => get_class($route),
                ];
                $newRoute['options']['route'] = substr($routePath, 1);

                if (isset($routeArray['defaults'])) {
                    $routeArray['defaults']['site-slug'] = $this->siteSlug;
                    $newRoute['options']['defaults'] = $routeArray['defaults'];
                }

                $mappedRoutes[$routeKey]['child_routes'][$routeName] = $newRoute;
            }
        }
        
        return $mappedRoutes;
    }

    private function isMyDomain() {
        return !preg_match("#(?<=\/s\/).*?(?=\/)#i", $this->url, $slug);
    }

    private function getDomain() {
        $domain = $this->entityManager->createQueryBuilder()
            ->select('m.domain')
            ->from('DomainManager\Entity\DomainSiteMapping', 'm')
            ->where('m.site_id = ?1')
            ->setParameter(1, $this->siteId)
            ->getQuery()
            ->getArrayResult();
        return count($domain) > 0 ? ($this->scheme . "://" . $domain[0]['domain']) : null;
    }

    private function getSiteId()
    {
        if(!$this->isMyDomain()) {
             $site = $this->entityManager->createQueryBuilder()
                ->select('m.site_id')
                ->from('DomainManager\Entity\DomainSiteMapping', 'm')
                ->leftJoin('Omeka\Entity\Site', 's', \Doctrine\ORM\Query\Expr\Join::WITH, 'm.site_id = s.id')
                ->where('s.slug = ?1')
                ->setParameter(1, $this->getSiteSlug())
                ->getQuery()
                ->getArrayResult();
            return count($site) > 0 ? $site[0]['site_id'] : null;
        }
        else {
            $site = $this->entityManager->createQueryBuilder()
                ->select('s.id')
                ->from('DomainManager\Entity\DomainSiteMapping', 'm')
                ->leftJoin('Omeka\Entity\Site', 's', \Doctrine\ORM\Query\Expr\Join::WITH, 'm.site_id = s.id')
                ->where('m.domain = ?1')
                ->orWhere("s.slug = ?2")
                ->setParameter(1, $this->domain)
                ->setParameter(2, $this->siteSlug)
                ->getQuery()
                ->getArrayResult();
            return count($site) > 0 ? $site[0]['id'] : null;
        }
    }

    private function getSiteSlug()
    {
        if(!$this->isMyDomain()) {
            preg_match("#(?<=\/s\/).*?(?=\/)#i", $this->url, $slug);
            return $slug[0];
        }
        else {
            $slugFromUrl = preg_replace("#{$this->siteIndicator}#", "", $this->url);
            $slug = $this->entityManager->createQueryBuilder()
                ->select('s.slug')
                ->from('DomainManager\Entity\DomainSiteMapping', 'm')
                ->leftJoin('Omeka\Entity\Site', 's', \Doctrine\ORM\Query\Expr\Join::WITH, 'm.site_id = s.id')
                ->where('m.domain = ?1')
                ->orWhere("s.slug = ?2")
                ->setParameter(1, $this->domain)
                ->setParameter(2, $slugFromUrl)
                ->getQuery()
                ->getArrayResult();
            return count($slug) > 0 ? $slug[0]['slug'] : null;
        }
    }

    private function getDefaultPage()
    {
        $defaultPage = $this->entityManager->createQueryBuilder()
            ->select('sp.slug')
            ->from('Omeka\Entity\Site', 's')
            ->innerJoin('Omeka\Entity\SitePage', 'sp', \Doctrine\ORM\Query\Expr\Join::WITH, 's.id = sp.site AND s.homepage = sp.id')
            ->where('s.id = ?1')
            ->orderBy('s.id', 'ASC')
            ->setParameter(1, $this->siteId)
            ->getQuery()
            ->getArrayResult();
        
        if (count($defaultPage) > 0) {
            return $defaultPage[0]['slug'];
        }
        else if(count($defaultPage) == 0 && !$this->isMyDomain()) {
            if(preg_match("#\/s\/.*?(?=\/)\/page\/.*#i", $this->url)) {
                $defaultPage = explode("/", $this->url);
                return end($defaultPage);
            }
        }
        
        return null;
    }

    private function redirect(AbstractController $controller, $url)
    {
        $separator = '';

        if (substr($url, 0, 1) != '/') {
            $separator = '/';
        }

        $url = "{$this->scheme}://{$this->domain}{$separator}{$url}";
        return $controller->plugin('redirect')->toUrl($url);
    }

    public function createRoute($routes = null)
    {
        $domain = $this->getDomain();
        
        if ($this->isIgnoredRoute() || is_null($domain)) {
            return;
        }

        $routes = is_null($routes) ? $this->router->getRoutes() : $routes;
        $this->routes[$this->siteSlug] = $this->routeTemplate($routes);
        $this->router->addRoutes($this->routes[$this->siteSlug]);
        
        if ($this->isMyDomain() && substr($this->url, 0, 3) == $this->siteIndicator) {
            $this->redirectUrl = trim(preg_replace("#{$this->siteIndicator}|{$this->siteSlug}#", '', $this->url), '/');
        }
        
        $doRedirect = false;
        $routeMatch = $this->router->match($this->event->getRequest());

        if(is_null($routeMatch)) {
            $doRedirect = true;

            if($this->redirectUrl[0] != "/") {
                $this->redirectUrl = "/" . $this->redirectUrl;
            }
            
            $this->event->getRequest()->setUri($this->redirectUrl);
            $routeMatch = $this->router->match($this->event->getRequest());
        }

        if (!is_null($routeMatch)) {
            if (!$this->isMyDomain()) {
                $this->redirectUrl = $domain . $this->redirectUrl;
            }
            else {
                /**
                 * route exists however it is the default omeka route and not the domain specific route
                 */
                $isOmekaDefaultRoute = stripos($this->url, $this->siteIndicator) !== false;

                if($this->isIndexPage() || $this->isDefaultPageRoute()) {
                    $doRedirect = true;
                    $this->redirectUrl = $this->defaultPageUrl;
                }
            }

            /*
             * append all query variables
             */
            if (strlen($this->redirectUrl) && strlen($this->query)) {
                $this->redirectUrl = $this->redirectUrl . '?' . $this->query;
            }
            
            /**
             * ensure we append the domain
             */
            if(!preg_match("#{$domain}#", $this->redirectUrl)) {
                $this->redirectUrl = $domain . "/". $this->redirectUrl;
            }
            
            if(strlen($this->redirectUrl) && $doRedirect) {
                header("Location: {$this->redirectUrl}");
                exit();
            }
        }
    }

    private function getMapping()
    {
        return $this->entityManager->createQueryBuilder()
            ->select('s.id site_id')
            ->addSelect('s.title site_title')
            ->addSelect('m.id mapping_id')
            ->addSelect('m.domain')
            ->from('Omeka\Entity\Site', 's')
            ->leftJoin('DomainManager\Entity\DomainSiteMapping', 'm', \Doctrine\ORM\Query\Expr\Join::WITH, 's.id = m.site_id')
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    private function hydrate($mapping_ids, $site_ids, $domains)
    {
        $length = count($mapping_ids);
        $data = [];

        /*
         * store the form variables in a stack.
         *
         * items with a mapping_id > 0 should be on top of the stack
         * so we don't get a database error when we do an insert and delete (when a domain name is transferred to a different site)
         */
        for ($index = 0; $index < $length; $index++) {
            $mapping_id = $mapping_ids[$index];
            $site_id = $site_ids[$index];
            $key = $mapping_id . '_' . $site_id;
            $data[$key] = [
                'index' => $index,
                'mapping_id' => $mapping_id,
                'site_id' => $site_id,
                'domain' => strtolower(trim(preg_replace('#(https?://|\/$)#', '', $domains[$index]))),
            ];
        }

        krsort($data);
        return array_values($data);
    }

    public function __construct(MvcEvent $event)
    {
        $this->event = $event;
        $this->entityManager = $this->event->getApplication()->getServiceManager()->get('Omeka\EntityManager');
        $this->routes = [];
        $this->errors = [];
    }

    public function init()
    {
        if(php_sapi_name() !== 'cli') {
            $this->initRoutingVariables();

            if (!$this->isIgnoredRoute()) {
                if (!$this->isPluginConfigured()) {
                    $renderer = $this->event->getApplication()->getServiceManager()->get('ViewPhpRenderer');
                    $view = new ViewModel();
                    $data = ['domain' => $this->domain];
                    $view->setTemplate('domain_not_configured');
                    $view->setVariables($data);
                    die($renderer->render($view));
                }
            }
        }
    }

    public function configurePlugin(PhpRenderer $renderer)
    {
        $view = new ViewModel();
        $view->setTemplate('config_module');

        $data = [
            'mappings' => $this->getMapping(),
            'errors' => $this->errors,
        ];

        $view->setVariables($data);
        return $renderer->render($view);
    }

    public function saveConfiguration(AbstractController $controller)
    {
        $this->controller = $controller;
        $request = $controller->getRequest();
        $mapping_ids = $request->getPost('mapping_id');
        $site_ids = $request->getPost('site_id');
        $domains = $request->getPost('domain');
        $this->errors = [];
        $domainExists = false;
        $data = $this->hydrate($mapping_ids, $site_ids, $domains);
        $length = count($data);

        for ($index = 0; $index < $length; $index++) {
            $row = $data[$index];
            $errorKey = $row['index'];
            $mapping_id = $row['mapping_id'];
            $site_id = $row['site_id'];
            $domain = $row['domain'];
            
            if (strlen($domain) > 0) {
                $validate = preg_match('#^(?!\-)(?:[a-zA-Z\d\-]{0,62}[a-zA-Z\d]\.){1,126}(?!\d+)[a-zA-Z\d]{1,63}$#', $domain);

                if ($validate === false || $validate === 0) {
                    $this->errors[$errorKey] = [
                        'error_message' => "{$domain} is not a valid url.",
                        'error_value' => $domain,
                    ];
                    continue;
                }

                for ($i = $index + 1; $i < $length; $i++) {
                    $currDomain = strtolower($data[$index]['domain']);
                    $nextDomain = strtolower($data[$i]['domain']);

                    if ((strlen($currDomain) > 0 && strlen($nextDomain) > 0) && ($nextDomain == $currDomain)) {
                        $errorKey = $data[$i]['index'];
                        $domainExists = true;
                        $this->errors[$errorKey] = [
                            'error_message' => "{$nextDomain} is already mapped to a site.",
                            'error_value' => $nextDomain,
                        ];
                        break;
                    }
                }
            }

            if ($domainExists) {
                continue;
            }

            if (strlen($mapping_id) > 0 && $mapping_id > 0) {
                $domainSiteMapping = $this->entityManager->getRepository(DomainSiteMapping::class)->find($mapping_id);

                if (strlen($domain) == 0) {
                    $this->entityManager->remove($domainSiteMapping);
                } else {
                    $domainSiteMapping->setSiteId($site_id);
                    $domainSiteMapping->setDomain($domain);
                    $this->entityManager->persist($domainSiteMapping);
                }

                $this->entityManager->flush();
            } else {
                if (strlen($domain) > 0) {
                    $domainSiteMapping = new DomainSiteMapping();
                    $domainSiteMapping->setSiteId($site_id);
                    $domainSiteMapping->setDomain($domain);
                    $this->entityManager->persist($domainSiteMapping);
                    $this->entityManager->flush();
                }
            }
        }

        return count($this->errors) == 0;
    }
}
