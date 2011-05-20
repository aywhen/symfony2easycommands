<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\FrameworkBundle\Command;

namespace Symfony\Bundle\FrameworkBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Bundle\FrameworkBundle\Util\Mustache;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Config\FileLocator;

/**
 * Command that places bundle web assets into a given directory.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class KendoctorInitBundleCommand extends Command {

    /**
     * @see Command
     */
    protected function configure() {
        $this
                ->setDefinition(array(
                    new InputArgument('namespace', InputArgument::REQUIRED, 'The namespace of the bundle to create'),
                    new InputArgument('bundleName', InputArgument::OPTIONAL, 'The optional bundle name'),
                ))
                ->setHelp(<<<EOT
The <info>init:bundle</info> command generates a new bundle with a basic skeleton.

<info>./app/console init:bundle "Vendor\HelloBundle" [bundleName]</info>

The bundle namespace must end with "Bundle" (e.g. <comment>Vendor\HelloBundle</comment>)
and it will place into src directory (e.g. <comment>src</comment>).

If you don't specify a bundle name (e.g. <comment>HelloBundle</comment>), the bundle name will
be the concatenation of the namespace segments (e.g. <comment>VendorHelloBundle</comment>).

Then this bundle will configured into orm's mappings and be ready for entity generation.

Register the bundle in AppKernel.php and register namespace in autoload.php

If app/config/mapping.orm.yml does not exisit, it will create it for your entity definition.

All these will be done, :), so, you can define all entities of yours in one yaml file app/config/mapping.orm.yml NOW.

Next command will be kendoctor:generate:entities until you have finished the entity definition requiring your db connection(DBAL)  info configured properly.
EOT
                )
                ->setName('kendoctor:init:bundle')
        ;
    }

    /**
     * @see Command
     *
     * @throws \InvalidArgumentException When the target directory does not exist
     */
    protected function execute(InputInterface $input, OutputInterface $output) {

        if (!preg_match('/Bundle$/', $namespace = $input->getArgument('namespace'))) {
            throw new \InvalidArgumentException('The namespace must end with Bundle.');
        }

        // validate namespace
        $namespace = strtr($namespace, '/', '\\');
        if (preg_match('/[^A-Za-z0-9_\\\-]/', $namespace)) {
            throw new \InvalidArgumentException('The namespace contains invalid characters.');
        }

        // user specified bundle name?
        $bundle = $input->getArgument('bundleName');
        if (!$bundle) {
            $bundle = strtr($namespace, array('\\' => ''));
        }

        if (!preg_match('/Bundle$/', $bundle)) {
            throw new \InvalidArgumentException('The bundle name must end with Bundle.');
        }

        // validate that the namespace is at least one level deep
        if (false === strpos($namespace, '\\')) {
            $msg = array();
            $msg[] = sprintf('The namespace must contain a vendor namespace (e.g. "VendorName\%s" instead of simply "%s").', $namespace, $namespace);
            $msg[] = 'If you\'ve specified a vendor namespace, did you forget to surround it with quotes (init:bundle "Acme\BlogBundle")?';

            throw new \InvalidArgumentException(implode("\n\n", $msg));
        }


        //should be 'src' for a niewbie, no choice
        $dir = "src";
        $root = $this->getApplication()->getKernel()->getRootDir();
        $locator = new FileLocator();
        $path = $locator->locate($root);

        // add trailing / if necessary
        $dir = '/' === substr($dir, -1, 1) ? $dir : $dir . '/';

        $targetDir = $dir . strtr($namespace, '\\', '/');


        if (file_exists($targetDir)) {
            throw new \RuntimeException(sprintf('Bundle "%s" already exists.', $bundle));
        }

        $filesystem = $this->container->get('filesystem');
        $filesystem->mirror(__DIR__ . '/../Resources/skeleton/bundle/generic', $targetDir);
        //should be yaml format, no choice.
        $filesystem->mirror(__DIR__ . '/../Resources/skeleton/bundle/' . 'yml', $targetDir);

        Mustache::renderDir($targetDir, array(
            'namespace' => $namespace,
            'bundle' => $bundle,
        ));

        rename($targetDir . '/Bundle.php', $targetDir . '/' . $bundle . '.php');
        $filesystem->mkdir($targetDir . '/Entity');


        $this->configBundleToEntityMappings($bundle, $path);

        $this->registerBundle($namespace, $bundle, $path);

        $this->registerNamespace($namespace, $path);

        $this->configDefaultControllerRouterOfBundle($bundle, $path, $targetDir);

        $this->createStandaloneEntityDefinitionYaml($targetDir);

        $this->giveNewbieSuggestion($output, $bundle);
    }

    private function createStandaloneEntityDefinitionYaml($targetDir) {
        $path = $targetDir . "/Resources/config/doctrine/";
        $this->container->get('filesystem')->mkdir($path);

        if (!file_exists($path . '/mapping.orm.yml')) {
            file_put_contents($path . '/mapping.orm.yml', $this->getSampleYamlEntityDefinition());
        }
    }

    private function configDefaultControllerRouterOfBundle($bundle, $path, $targetDir) {
        $lowercase = strtolower($bundle);
        $config = Yaml::load($path . '/config/routing.yml');
        if (!is_array($config))
            $config = array();
        if (!isset($config[$lowercase])) {
            $config[$lowercase] = array(
                'resource' => sprintf('@%s/Resources/config/routing.yml', $bundle)
                    );
        }
        $config = Yaml::dump($config, 10);
        file_put_contents($path . '/config/routing.yml', $config);

        $config = Yaml::load($targetDir . '/Resources/config/routing.yml');
        if (!is_array($config))
            $config = array();
        if (!isset($config[$lowercase . '_default'])) {
            $config[$lowercase . '_default'] = array(
                'pattern' => '/' . $lowercase,
                'defaults' => array(
                    '_controller' => $bundle . ':Default:index'
                )
                    );
        }
        print_r($config);
        $config = Yaml::dump($config, 10);
        file_put_contents($targetDir . '/Resources/config/routing.yml', $config);
    }

    private function giveNewbieSuggestion($output, $bundle) {
        $content = <<< GUIDE
   
OK, the %s have been configured into orm's mappings in app/config.yml and is ready for entity generation 

The %s has been put into registerBundles in AppKernel.php and its namespace has been put into  registerNames  in autoload.php

If app/config/mapping.orm.yml does not exisit, this command will create it for your entity definition.

All these have been done, :), GOOD LUCK, you can define all entities of yours in one yaml file app/config/mapping.orm.yml NOW.

And a DefaultController has prepared for your fun and its router has been configured for you. CHECK bundle's Resources/config/routing.yml.

You can type http://localhost/.../web/app_dev.php/%s to see a  WORLD.

Next command will be kendoctor:generate:entities until you have finished the entity definition requiring your db connection(DBAL)  info configured properly.
GUIDE;
        $output->writeln(sprintf($content, $bundle, $bundle, strtolower($bundle)));
    }

    private function findBundleMappings(&$config, $appendBundle) {
        $mappings = array();

        while (list($key, $value) = each($config)) {
            if ($key === "mappings") {
                if (is_array($value) && !array_key_exists($appendBundle, $value)) {
                    $config[$key] = array_merge($value, array($appendBundle => null));
                }
                return true;
            }

            if (is_array($value)) {
                $return = $this->findBundleMappings($config[$key], $appendBundle);
                if ($return)
                    return true;
            }
        }

        return false;
    }

    private function configBundleToEntityMappings($bundle, $path) {
        $config = Yaml::load($path . '/config/config.yml');

        $mappings = $this->findBundleMappings($config, $bundle);
        $config = Yaml::dump($config, 10);

        file_put_contents($path . '/config/config.yml', $config);
    }

    private function registerBundle($namespace, $bundle, $path) {
        $insertLine = "new " . $namespace . '\\' . $bundle . "(),\r\n";

        $this->insertLineByMark(');', $insertLine, $path . '/AppKernel.php');
    }

    private function registerNamespace($namespace, $path) {
        //get namespace name
        $tokens = explode("\\", $namespace);
        //format namespace => directory
        $insertLine = "    '" . $tokens[0] . "'             => __DIR__.'/../src',\r\n";

        $this->insertLineByMark('));', $insertLine, $path . '/autoload.php');
    }

    private function insertLineByMark($mark, $insertLine, $file) {
        $found = false;
        $content = '';

        $handle = @fopen($file, "r");
        if ($handle) {
            while (!feof($handle)) {
                $line = fgets($handle);
                if (trim($line) === trim($insertLine))
                    $found = true;
                if (trim($line) === $mark && !$found) {
                    $content .= $insertLine;
                    $content .= $line;
                    $found = true;
                } else {
                    $content .= $line;
                }
            }
            fclose($handle);
        }
        file_put_contents($file, $content);
    }

    private function getSampleYamlEntityDefinition($bundle) {
        return <<< YAML
Namespace\MyBundle\Entity\User:
  type: entity
  table: cms_users
  id:
    id:
      type: integer
      generator:
        strategy: AUTO
  fields:
    name:
      type: string
      length: 50
  oneToOne:
    address:
      targetEntity: Address
      joinColumn:
        name: address_id
        referencedColumnName: id
  oneToMany:
    phonenumbers:
      targetEntity: Phonenumber
      mappedBy: user
      cascade: ["persist", "merge"]
  manyToMany:
    groups:
      targetEntity: Group
      joinTable:
        name: cms_users_groups
        joinColumns:
          user_id:
            referencedColumnName: id
        inverseJoinColumns:
          group_id:
            referencedColumnName: id
  lifecycleCallbacks:
    prePersist: [ doStuffOnPrePersist, doOtherStuffOnPrePersistToo ]
    postPersist: [ doStuffOnPostPersist ] 
YAML;
    }

}
