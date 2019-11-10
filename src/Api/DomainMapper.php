<?php

namespace OmekaSDomainManager\Api;

use OmekaSDomainManager\Entity\DomainSiteMapping;
use Zend\Mvc\Controller\AbstractController;
use Zend\Mvc\MvcEvent;
use Zend\View\Model\ViewModel;
use Zend\View\Renderer\PhpRenderer;

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
    /** @var \Zend\Router\Http\TreeRouteStack $router */
    private $router;
    private $domain;
    private $siteSlug;
    private $siteId;
    private $redirectUrl;
    private $defaultPage;

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
            ->from('OmekaSDomainManager\Entity\DomainSiteMapping', 'm')
            ->where('m.domain = ?1')
            ->setParameter(1, $this->domain)
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

    private function routeTemplate()
    {
        $routeKey = "{$this->siteSlug}-routes";

        /**
         * default route options
         */
        $routeType = \Zend\Router\Http\Literal::class;
        $routePath = '/';
        $rootRouteDefaults = [
            '__NAMESPACE__' => 'Omeka\Controller\Site',
            '__SITE__' => true,
        ];

        if (is_null($this->defaultPage)) {
            /**
             * set the default route to browse item
             */
            $rootRouteDefaults = array_merge(
                $rootRouteDefaults,
                [
                    'controller' => 'Item',
                    'action' => 'browse',
                    'site-slug' => $this->siteSlug,
                ]
            );

            $defaultPageRouteDefaults = [
                'controller' => 'Item',
                'action' => 'browse',
                'site-slug' => $this->siteSlug,
            ];
        } else {
            /**
             * set the default route to the default page
             */
            $rootRouteDefaults = array_merge(
                $rootRouteDefaults,
                [
                    'controller' => 'Page',
                    'action' => 'show',
                    'site-slug' => $this->siteSlug,
                    'page-slug' => $this->defaultPage,
                ]
            );

            $defaultPageRouteDefaults = [
                'controller' => 'Page',
                'action' => 'show',
                'site-slug' => $this->siteSlug,
                'page-slug' => $this->defaultPage,
            ];
        }

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
                        'type' => \Zend\Router\Http\Literal::class,
                        'options' => [
                            'route' => $this->defaultPageRoute(),
                            'defaults' => $defaultPageRouteDefaults,
                        ],
                    ],
                    'resource' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => ':controller[/:action]',
                            'defaults' => [
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
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => ':controller/:id[/:action]',
                            'defaults' => [
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
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => 'item-set/:item-set-id',
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
                    'page' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            'route' => 'page[/:page-slug]',
                            'defaults' => [
                                'controller' => 'Page',
                                'action' => 'show',
                                'site-slug' => $this->siteSlug,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        /*
         * map all existing routes to the domain (defined by the config files)
         */
        foreach ($this->event->getApplication()->getServiceManager()->get('config')['router']['routes'] as $mainRouteKey => $mainRouteArray) {
            if ($mainRouteKey === 'site') {
                foreach ($mainRouteArray['child_routes'] as $childRouteKey => $childRouteArray) {
                    if (isset($mappedRoutes[$routeKey]['child_routes'][$childRouteKey])) {
                        continue;
                    }

                    $childRouteArray['options']['route'] = substr($childRouteArray['options']['route'], 1);

                    /*
                     * we will assume that the default controller is called Index
                     */
                    if (!isset($childRouteArray['options']['defaults']['controller'])) {
                        $childRouteArray['options']['defaults']['controller'] = 'Index';
                    }

                    $childRouteArray['options']['defaults']['site-slug'] = $this->siteSlug;

                    if (isset($childRouteArray['options']['constraints']) && stripos($childRouteArray['options']['route'], ':controller') !== false) {
                        $childRouteArray['options']['constraints']['controller'] = $controllerContraints;
                    }

                    $mappedRoutes[$routeKey]['child_routes'][$childRouteKey] = $childRouteArray;
                }
            }
        }

        /*
         * map all dynamically created routes to the domain
         */
        foreach ($this->router->getRoutes() as $routeName => $route) {
            if (!in_array($routeName, array_merge(['top', 'site'], $this->ignoredRoutes))) {
                $routeArray = [];

                foreach ((array) $route as $k => $v) {
                    $routeArray[preg_replace('#\W#i', '', $k)] = $v;
                }

                /**
                 * build the route url
                 */
                $routePath = '';
                if (isset($routeArray['parts'])) {
                    foreach ($routeArray['parts'] as $part) {
                        list($type, $path) = $part;
                        // The module Scripto use another route format, at top
                        // level, but with optional site slug.
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

                /*
                 * ignore admin routes
                 */
                if (stripos($routePath, 'admin') === false) {
                    $newRoute = [
                        'type' => strtolower(substr(get_class($route), strripos(get_class($route), '\\') + 1)),
                    ];

                    if (strlen($routePath)) {
                        $newRoute['options']['route'] = substr($routePath, 1);
                    }

                    if (isset($routeArray['defaults'])) {
                        $routeArray['defaults']['site-slug'] = $this->siteSlug;
                        $newRoute['options']['defaults'] = $routeArray['defaults'];
                    }

                    $mappedRoutes[$routeKey]['child_routes'][$routeName] = $newRoute;
                }
            }
        }

        return $mappedRoutes;
    }

    private function getSiteId()
    {
        $site = $this->entityManager->createQueryBuilder()
            ->select('s.id')
            ->from('OmekaSDomainManager\Entity\DomainSiteMapping', 'm')
            ->leftJoin('Omeka\Entity\Site', 's', \Doctrine\ORM\Query\Expr\Join::WITH, 'm.site_id = s.id')
            ->where('m.domain = ?1')
            ->setParameter(1, $this->domain)
            ->getQuery()
            ->getArrayResult();

        return count($site) > 0 ? $site[0]['id'] : null;
    }

    private function getSiteSlug()
    {
        $slug = $this->entityManager->createQueryBuilder()
            ->select('s.slug')
            ->from('OmekaSDomainManager\Entity\DomainSiteMapping', 'm')
            ->leftJoin('Omeka\Entity\Site', 's', \Doctrine\ORM\Query\Expr\Join::WITH, 'm.site_id = s.id')
            ->where('m.domain = ?1')
            ->setParameter(1, $this->domain)
            ->getQuery()
            ->getArrayResult();

        return count($slug) > 0 ? $slug[0]['slug'] : null;
    }

    private function getDefaultPage()
    {
        $defaultPage = $this->entityManager->createQueryBuilder()
            ->select('sp.slug')
            ->from('Omeka\Entity\SitePage', 'sp')
            ->innerJoin('OmekaSDomainManager\Entity\DomainSiteMapping', 'm', \Doctrine\ORM\Query\Expr\Join::WITH, 'sp.id = m.site_page_id')
            ->where('sp.site = ?1')
            ->orderBy('sp.id', 'ASC')
            ->setParameter(1, $this->siteId)
            ->getQuery()
            ->getArrayResult();

        return count($defaultPage) > 0 ? $defaultPage[0]['slug'] : null;
    }

    private function redirect(AbstractController $controller, $url)
    {
        $separator = '';

        if (substr($url, 0, 1) != '/') {
            $separator = '/';
        }

        $url = "{$this->scheme}://{$this->domain}{$separator}{$url}";
        $controller->plugin('redirect')->toUrl($url);
    }

    private function createRoute()
    {
        if ($this->router->hasRoute($this->siteSlug) === false) {
            $this->routes[$this->siteSlug] = $this->routeTemplate();
            $this->router->addRoutes($this->routes[$this->siteSlug]);
        }

        if (substr($this->url, 0, 3) == $this->siteIndicator) {
            $this->redirectUrl = trim(preg_replace("#{$this->siteIndicator}|{$this->siteSlug}#", '', $this->url), '/');
        }

        $doRedirect = true;
        $routeMatch = $this->router->match($this->event->getRequest());

        if (!is_null($routeMatch)) {
            // $routeName = $routeMatch->getMatchedRouteName();
            $doRedirect = false;

            /**
             * route exists however it is the default omeka route and not the domain specific route
             */
            $isOmekaDefaultRoute = stripos($this->url, $this->siteIndicator) !== false;

            if ($isOmekaDefaultRoute) {
                $doRedirect = true;
            }

            if ($this->isDefaultPageRoute()) {
                $doRedirect = true;
                $this->redirectUrl = '/';
            }

            /*
             * append all query variables
             */
            if (strlen($this->query) > 0) {
                $this->redirectUrl = $this->redirectUrl . '?' . $this->query;
            }

            if (strlen($this->redirectUrl) > 0 && $doRedirect) {
                $this->event->getApplication()->getEventManager()->getSharedManager()->attach(
                    'Zend\Mvc\Controller\AbstractActionController',
                    'dispatch',
                    function ($event) {
                        /*
                         * redirect is only invoked when $routeMatch is not null
                         */
                        $controller = $event->getTarget();
                        $this->redirect($controller, $this->redirectUrl);
                    },
                    100
                );
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
            ->addSelect('m.site_page_id')
            ->from('Omeka\Entity\Site', 's')
            ->leftJoin('OmekaSDomainManager\Entity\DomainSiteMapping', 'm', \Doctrine\ORM\Query\Expr\Join::WITH, 's.id = m.site_id')
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    private function getSitePages()
    {
        $data = $this->entityManager->createQueryBuilder()
            ->select('s.id site_id')
            ->addSelect('s.title site_title')
            ->addSelect('p.id site_page_id')
            ->addSelect('p.title site_page_title')
            ->from('Omeka\Entity\Site', 's')
            ->leftJoin('Omeka\Entity\SitePage', 'p', \Doctrine\ORM\Query\Expr\Join::WITH, 's = p.site')
            ->orderBy('s.id', 'ASC')
            ->orderBy('p.title', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $site_pages = [];

        foreach ($data as $site_page) {
            $site_pages[$site_page['site_id']][] = [
                'site_page_id' => $site_page['site_page_id'],
                'site_page_title' => $site_page['site_page_title'],
            ];
        }

        return $site_pages;
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
        $this->initRoutingVariables();

        if (!$this->isIgnoredRoute()) {
            if ($this->isPluginConfigured()) {
                $this->createRoute();
            } else {
                $renderer = $this->event->getApplication()->getServiceManager()->get('ViewPhpRenderer');
                $view = new ViewModel();
                $data = ['domain' => $this->domain];
                $view->setTemplate('domain_not_configured');
                $view->setVariables($data);
                die($renderer->render($view));
            }
        }
    }

    public function configurePlugin(PhpRenderer $renderer)
    {
        $view = new ViewModel();
        $view->setTemplate('config_module');

        $data = [
            'mappings' => $this->getMapping(),
            'site_pages' => $this->getSitePages(),
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
            $site_page_id = $this->getHomepage($site_id);

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
                    $domainSiteMapping->setSitePageId($site_page_id);
                    $this->entityManager->persist($domainSiteMapping);
                }

                $this->entityManager->flush();
            } else {
                if (strlen($domain) > 0) {
                    $domainSiteMapping = new DomainSiteMapping();
                    $domainSiteMapping->setSiteId($site_id);
                    $domainSiteMapping->setDomain($domain);
                    $domainSiteMapping->setSitePageId($site_page_id);
                    $this->entityManager->persist($domainSiteMapping);
                    $this->entityManager->flush();
                }
            }
        }

        return count($this->errors) == 0;
    }

    protected function getHomepage($site_id)
    {
        /** @var \Omeka\Entity\Site $site */
        $site = $this->entityManager->getRepository(\Omeka\Entity\Site::class)
            ->findOneBy([
                'id' => $site_id,
            ]);
        if (empty($site)) {
            return null;
        }

        // @see \Omeka\Controller\Site\IndexController::indexAction()
        // Get the defined home page, if any.
        $homepage = $site->getHomepage();
        if ($homepage) {
            return $homepage->getId();
        }

        // The api doesn't allow to get a site by id or a site page by slug, but
        // is needed to get the linked pages.
        $api = $this->controller->api();

        // Get the linked home page, if any.
        $siteRepr = $api->read('sites', ['id' => $site_id])->getContent();
        $linkedPages = $siteRepr->linkedPages();
        if ($linkedPages) {
            $sitePageRepr = current($linkedPages);
            $sitePage = $this->entityManager->getRepository(\Omeka\Entity\SitePage::class)
                ->findOneBy([
                    'site' => $site,
                    'slug' => $sitePageRepr->slug(),
                ]);
            if ($sitePage) {
                return $sitePage->getId();
            }
        }

        return null;
    }
}
