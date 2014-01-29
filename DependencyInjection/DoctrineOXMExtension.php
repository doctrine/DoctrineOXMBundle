<?php

namespace Doctrine\Bundle\OXMBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Bridge\Doctrine\DependencyInjection\AbstractDoctrineExtension;

/**
 * Doctrine OXM extension.
 *
 * @author Igor Golovanov <igor.golovanov@gmail.com>
 */
class DoctrineOXMExtension extends AbstractDoctrineExtension
{
    /**
     * Responds to the doctrine_mongodb configuration parameter.
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        // Load DoctrineMongoDBBundle/Resources/config/mongodb.xml
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('oxm.xml');

        $processor = new Processor();
        $configuration = new Configuration($container->getParameter('kernel.debug'));
        $config = $processor->processConfiguration($configuration, $configs);

        // can't currently default this correctly in Configuration
        if (!isset($config['metadata_cache_driver'])) {
            $config['metadata_cache_driver'] = array('type' => 'array');
        }

        if (empty ($config['default_storage'])) {
            $keys = array_keys($config['storages']);
            $config['default_storage'] = reset($keys);
        }

        if (empty ($config['default_xml_marshaller'])) {
            $keys = array_keys($config['xml_entity_managers']);
            $config['default_xml_marshaller'] = reset($keys);
        }

        if (empty ($config['default_xml_entity_manager'])) {
            $keys = array_keys($config['xml_entity_managers']);
            $config['default_xml_entity_manager'] = reset($keys);
        }

        // set some options as parameters and unset them
        $config = $this->overrideParameters($config, $container);

        // load the storages
        $this->loadStorages($config['storages'], $container);

        // load the xml-entity managers
        $this->loadXmlEntityManagers(
            $config['xml_entity_managers'],
            $config['default_xml_entity_manager'],
            $config['default_storage'],
            $config['metadata_cache_driver'],
            $container
        );
    }

    /**
     * Loads the xml-entity managers configuration.
     *
     * @param array $xemConfigs An array of xml-entity manager configs
     * @param string $defaultXEM The default xml-entity manager name
     * @param string $defaultStorage The default db name
     * @param string $defaultMetadataCache The default metadata cache configuration
     * @param ContainerBuilder $container A ContainerBuilder instance
     */
    protected function loadXmlEntityManagers(array $xemConfigs, $defaultXEM, $defaultStorage, $defaultMetadataCache, ContainerBuilder $container)
    {
        foreach ($xemConfigs as $name => $xmlEntityManager) {
            $xmlEntityManager['name'] = $name;
            $this->loadXmlEntityManager(
                $xmlEntityManager,
                $defaultXEM,
                $defaultStorage,
                $defaultMetadataCache,
                $container
            );
        }
        $container->setParameter('doctrine.oxm.xml_entity_managers', array_keys($xemConfigs));
        $container->setParameter('doctrine.oxm.xml_marshallers', array_keys($xemConfigs));
    }

    /**
     * Loads a xml-entity manager configuration.
     *
     * @param array $xmlEntityManager        A xml-entity manager configuration array
     * @param string $defaultXEM The default xml-entity manager name
     * @param string $defaultStorage The default db name
     * @param string $defaultMetadataCache The default metadata cache configuration
     * @param ContainerBuilder $container A ContainerBuilder instance
     */
    protected function loadXmlEntityManager(array $xmlEntityManager, $defaultXEM, $defaultStorage, $defaultMetadataCache, ContainerBuilder $container)
    {
        $defaultStorage = isset($xmlEntityManager['storage']) ? $xmlEntityManager['storage'] : $defaultStorage;
        $configServiceName = sprintf('doctrine.oxm.%s_configuration', $xmlEntityManager['name']);

        if ($container->hasDefinition($configServiceName)) {
            $oxmConfigDef = $container->getDefinition($configServiceName);
        } else {
            $oxmConfigDef = new Definition('%doctrine.oxm.configuration.class%');
            $container->setDefinition($configServiceName, $oxmConfigDef);
        }

        $this->loadXmlEntityManagerBundlesMappingInformation($xmlEntityManager, $oxmConfigDef, $container);
        $this->loadXmlEntityManagerMetadataCacheDriver($xmlEntityManager, $container, $defaultMetadataCache);

        $methods = array(
            'setMetadataCacheImpl' => new Reference(sprintf('doctrine.oxm.%s_metadata_cache', $xmlEntityManager['name'])),
            'setMetadataDriverImpl' => new Reference(sprintf('doctrine.oxm.%s_metadata_driver', $xmlEntityManager['name'])),
            'setProxyDir' => '%doctrine.oxm.proxy_dir%',
            'setProxyNamespace' => '%doctrine.oxm.proxy_namespace%',
            'setAutoGenerateProxyClasses' => '%doctrine.oxm.auto_generate_proxy_classes%',
           // 'setDefaultStorage' => $defaultStorage,
        );

//        if ($xmlEntityManager['logging']) {
//            $methods['setLoggerCallable'] = array(new Reference('doctrine.oxm.logger'), 'logQuery');
//        }

        foreach ($methods as $method => $arg) {
            if ($oxmConfigDef->hasMethodCall($method)) {
                $oxmConfigDef->removeMethodCall($method);
            }
            $oxmConfigDef->addMethodCall($method, array($arg));
        }

        // event manager
        $eventManagerName = isset($xmlEntityManager['event_manager']) ? $xmlEntityManager['event_manager'] : $xmlEntityManager['name'];
        $eventManagerId = sprintf('doctrine.oxm.%s_event_manager', $eventManagerName);
        if (!$container->hasDefinition($eventManagerId)) {
            $eventManagerDef = new Definition('%doctrine.oxm.event_manager.class%');
            $eventManagerDef->addTag('doctrine.oxm.event_manager');
            $eventManagerDef->setPublic(false);
            $container->setDefinition($eventManagerId, $eventManagerDef);
        }

        $oxmXemArgs = array(
            new Reference(sprintf('doctrine.oxm.%s_storage', isset($xmlEntityManager['storage']) ? $xmlEntityManager['storage'] : $xmlEntityManager['name'])),
            new Reference(sprintf('doctrine.oxm.%s_configuration', $xmlEntityManager['name'])),
            new Reference($eventManagerId),
        );
        $oxmXemDef = new Definition('%doctrine.oxm.xml_entity_manager.class%', $oxmXemArgs);
        $oxmXemDef->setFactoryClass('%doctrine.oxm.xml_entity_manager.class%');
       // $oxmXemDef->setFactoryMethod('create');
        $oxmXemDef->addTag('doctrine.oxm.xml_entity_manager');
        $container->setDefinition(sprintf('doctrine.oxm.%s_xml_entity_manager', $xmlEntityManager['name']), $oxmXemDef);

        // oxm marshaller
        $oxmMarshallerDef = new Definition('%doctrine.oxm.xml_marshaller.class%');
        $oxmMarshallerDef->setFactoryService(sprintf('doctrine.oxm.%s_xml_entity_manager', $xmlEntityManager['name']));
        $oxmMarshallerDef->setFactoryMethod('getMarshaller');
        $container->setDefinition(sprintf('doctrine.oxm.%s_xml_marshaller', $xmlEntityManager['name']), $oxmMarshallerDef);

        if ($xmlEntityManager['name'] == $defaultXEM) {
            $container->setAlias(
                'doctrine.oxm.xml_entity_manager',
                new Alias(sprintf('doctrine.oxm.%s_xml_entity_manager', $xmlEntityManager['name']))
            );
            $container->setAlias(
                'doctrine.oxm.xml_marshaller',
                new Alias(sprintf('doctrine.oxm.%s_xml_marshaller', $xmlEntityManager['name']))
            );
            $container->setAlias(
                'doctrine.oxm.event_manager',
                new Alias(sprintf('doctrine.oxm.%s_event_manager', $xmlEntityManager['name']))
            );
        }
    }


    /**
     * Loads the configured xml-entity manager metadata cache driver.
     *
     * @param array $config                 A configured xml-entity manager array
     * @param ContainerBuilder $container   A ContainerBuilder instance
     * @param array $defaultMetadataCache   The default metadata cache configuration array
     */
    protected function loadXmlEntityManagerMetadataCacheDriver(array $xmlEntityManager, ContainerBuilder $container, $defaultMetadataCache)
    {
        $xemMetadataCacheDriver = isset($xmlEntityManager['metadata_cache_driver']) ? $xmlEntityManager['metadata_cache_driver'] : $defaultMetadataCache;
        $type = $xemMetadataCacheDriver['type'];

        if ('memcache' === $type) {
            $memcacheClass = isset($xemMetadataCacheDriver['class']) ? $xemMetadataCacheDriver['class'] : sprintf('%%doctrine.oxm.cache.%s.class%%', $type);
            $cacheDef = new Definition($memcacheClass);
            $memcacheHost = isset($xemMetadataCacheDriver['host']) ? $xemMetadataCacheDriver['host'] : '%doctrine.oxm.cache.memcache_host%';
            $memcachePort = isset($xemMetadataCacheDriver['port']) ? $xemMetadataCacheDriver['port'] : '%doctrine.oxm.cache.memcache_port%';
            $memcacheInstanceClass = isset($xemMetadataCacheDriver['instance-class']) ? $xemMetadataCacheDriver['instance-class'] : (isset($xemMetadataCacheDriver['instance_class']) ? $xemMetadataCacheDriver['instance_class'] : '%doctrine.oxm.cache.memcache_instance.class%');
            $memcacheInstance = new Definition($memcacheInstanceClass);
            $memcacheInstance->addMethodCall('connect', array($memcacheHost, $memcachePort));
            $container->setDefinition(sprintf('doctrine.oxm.%s_memcache_instance', $xmlEntityManager['name']), $memcacheInstance);
            $cacheDef->addMethodCall('setMemcache', array(new Reference(sprintf('doctrine.oxm.%s_memcache_instance', $xmlEntityManager['name']))));
        } else {
             $cacheDef = new Definition(sprintf('%%doctrine.oxm.cache.%s.class%%', $type));
        }

        $container->setDefinition(sprintf('doctrine.oxm.%s_metadata_cache', $xmlEntityManager['name']), $cacheDef);
    }

    /**
     * Loads the configured storages.
     *
     * @param array $storages An array of storages configurations
     * @param ContainerBuilder $container A ContainerBuilder instance
     */
    protected function loadStorages(array $storages, ContainerBuilder $container)
    {
        foreach ($storages as $name => $storage) {
            $oxmStorageType = $storage['type'];
            $oxmStorageDef = new Definition(sprintf('%%doctrine.oxm.%s_storage.class%%', $oxmStorageType));


            if ('filesystem' == $oxmStorageType) {
                $oxmStorageDef->addArgument($storage['path']);
                $oxmStorageDef->addArgument($storage['extension']);
            }
            $container->setDefinition(sprintf('doctrine.oxm.%s_storage', $name), $oxmStorageDef);
        }
    }


    /**
     * Loads an OXM xml-entity managers bundle mapping information.
     *
     * There are two distinct configuration possibilities for mapping information:
     *
     * 1. Specify a bundle and optionally details where the entity and mapping information reside.
     * 2. Specify an arbitrary mapping location.
     *
     * @example
     *
     *  doctrine.oxm:
     *     mappings:
     *         MyBundle1: ~
     *         MyBundle2: yml
     *         MyBundle3: { type: annotation, dir: XmlEntity/ }
     *         MyBundle4: { type: xml, dir: Resources/config/doctrine/mapping }
     *         MyBundle5:
     *             type: yml
     *             dir: [bundle-mappings1/, bundle-mappings2/]
     *             alias: BundleAlias
     *         arbitrary_key:
     *             type: xml
     *             dir: %kernel.dir%/../src/vendor/DoctrineExtensions/lib/DoctrineExtensions/XmlEntity
     *             prefix: DoctrineExtensions\XmlEntity\
     *             alias: DExt
     *
     * In the case of bundles everything is really optional (which leads to autodetection for this bundle) but
     * in the mappings key everything except alias is a required argument.
     *
     * @param array $xmlEntityManager A configured OXM xml-entity manager.
     * @param Definition A Definition instance
     * @param ContainerBuilder $container A ContainerBuilder instance
     */
    protected function loadXmlEntityManagerBundlesMappingInformation(array $xmlEntityManager, Definition $oxmConfigDef, ContainerBuilder $container)
    {
        // reset state of drivers and alias map. They are only used by this methods and children.
        $this->drivers = array();
        $this->aliasMap = array();

        $this->loadMappingInformation($xmlEntityManager, $container);
        $this->registerMappingDrivers($xmlEntityManager, $container);

        if ($oxmConfigDef->hasMethodCall('setEntityNamespaces')) {
            // TODO: Can we make a method out of it on Definition? replaceMethodArguments() or something.
            $calls = $oxmConfigDef->getMethodCalls();
            foreach ($calls as $call) {
                if ($call[0] == 'setEntityNamespaces') {
                    $this->aliasMap = array_merge($call[1][0], $this->aliasMap);
                }
            }
            $method = $oxmConfigDef->removeMethodCall('setEntityNamespaces');
        }
        $oxmConfigDef->addMethodCall('setEntityNamespaces', array($this->aliasMap));
    }


    /**
     * Uses some of the extension options to override DI extension parameters.
     *
     * @param array $options The available configuration options
     * @param ContainerBuilder $container A ContainerBuilder instance
     */
    protected function overrideParameters($options, ContainerBuilder $container)
    {
        $overrides = array(
            'proxy_namespace',
            'proxy_dir',
            'auto_generate_proxy_classes',
        );

        foreach ($overrides as $key) {
            if (isset($options[$key])) {
                $container->setParameter('doctrine.oxm.'.$key, $options[$key]);

                // the option should not be used, the parameter should be referenced
                unset($options[$key]);
            }
        }

        return $options;
    }


    protected function getObjectManagerElementName($name)
    {
        return 'doctrine.oxm.' . $name;
    }

    protected function getMappingObjectDefaultName()
    {
        return 'XmlEntity';
    }

    protected function getMappingResourceConfigDirectory()
    {
        return 'Resources/config/doctrine';
    }

    protected function getMappingResourceExtension()
    {
        return 'oxm';
    }

    public function getAlias()
    {
        return 'doctrine_oxm';
    }

    /**
     * @return string
     */
    public function getXsdValidationBasePath()
    {
        return __DIR__.'/../Resources/config/schema';
    }
}
