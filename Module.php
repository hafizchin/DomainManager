<?php

namespace DomainManager;

use DomainManager\Api\DomainMapper;
use Omeka\Module\AbstractModule;
use Zend\Mvc\MvcEvent;

use Zend\ModuleManager\ModuleManager;

use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Mvc\Controller\AbstractController;
use Zend\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    private $domainMapper;

    /**
     * modules are loaded alphabetical in ascending order, which means
     * modules that are lexicographically greater than this module will not be mapped.
     * 
     * in order for us to map dynamically created routes, we must then listen to
     * the route event and then generate the route based on that context
     */
    public function init(ModuleManager $manager)
    {
        $eventManager = $manager->getEventManager();
        $sharedEventManager = $eventManager->getSharedManager();
        $sharedEventManager->attach('Zend\Mvc\Application', MvcEvent::EVENT_ROUTE, function(MvcEvent $event) {
            $this->domainMapper->createRoute($event->getRouter()->getRoutes());
        }, 100);
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        $this->domainMapper = new DomainMapper($event);
        $this->domainMapper->init();
    }

    public function getConfig()
    {
        return include __DIR__.'/config/module.config.php';
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        return $this->domainMapper->configurePlugin($renderer);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        return $this->domainMapper->saveConfiguration($controller);
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
        $sql = '
            CREATE TABLE `domain_site_mapping` (
                `id` INT NOT NULL AUTO_INCREMENT,
                `site_id` INT NOT NULL,
                `domain` VARCHAR(100) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE INDEX `DOMAIN_SITE_MAPPING_SITE_ID_UNIQUE` (`site_id` ASC),
                UNIQUE INDEX `DOMAIN_SITE_MAPPING_DOMAIN_UNIQUE` (`domain` ASC),
                CONSTRAINT `FK_DOMAIN_SITE_MAPPING_SITE_ID`
                    FOREIGN KEY (`site_id`)
                    REFERENCES `site` (`id`)
                    ON DELETE RESTRICT
                    ON UPDATE RESTRICT)
            DEFAULT CHARACTER SET utf8mb4
            COLLATE utf8mb4_unicode_ci
            ENGINE = InnoDB
        ';
        $connection->exec($sql);
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $sqls = [
            'ALTER TABLE domain_site_mapping DROP FOREIGN KEY FK_DOMAIN_SITE_MAPPING_SITE_ID',
            'DROP TABLE domain_site_mapping',
        ];

        $connection = $serviceLocator->get('Omeka\Connection');

        foreach ($sqls as $sql) {
            $connection->exec($sql);
        }
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator)
    {
        $connection = $serviceLocator->get('Omeka\Connection');
       
        if (version_compare($oldVersion, "1.2", "<")) {
            if (count($connection->query("SHOW COLUMNS FROM domain_site_mapping LIKE 'site_page_id'")->fetchAll())) {
                try {
                    $connection->exec("ALTER TABLE domain_site_mapping DROP FOREIGN KEY FK_DOMAIN_SITE_MAPPING_SITE_PAGE_ID");
                    $connection->exec("ALTER TABLE `domain_site_mapping` DROP `site_page_id`");
                }
                catch (\Exception $e) {
                }
            }
        }
    }
}
