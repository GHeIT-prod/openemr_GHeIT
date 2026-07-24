<?php

/**
 * OpenEMR <https://open-emr.org>.
 *
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Core;

use Aws\S3\S3ClientInterface;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\FileStorage\FileStorageConfig;
use OpenEMR\Services\FileStorage\FileStorageInterface;
use OpenEMR\Services\FileStorage\FileMetadataRepositoryInterface;
use OpenEMR\Services\FileStorage\FileMetadataService;
use OpenEMR\Services\FileStorage\FileMetadataServiceInterface;
use OpenEMR\Services\FileStorage\FileUploadValidator;
use OpenEMR\Services\FileStorage\FileUploadValidatorInterface;
use OpenEMR\Services\FileStorage\PatientDocumentRecordRepositoryInterface;
use OpenEMR\Services\FileStorage\PatientDocumentStorageService;
use OpenEMR\Services\FileStorage\S3ClientFactory;
use OpenEMR\Services\FileStorage\S3FileStorage;
use OpenEMR\Services\FileStorage\S3ObjectKeyGenerator;
use OpenEMR\Services\FileStorage\SqlFileMetadataRepository;
use OpenEMR\Services\FileStorage\SqlPatientDocumentRecordRepository;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass;

/**
 * Class Kernel.
 *
 * This is the core of OpenEMR. It is a thin class enabling service containers,
 * event dispatching for now.
 *
 * @package OpenEMR
 * @subpackage Core
 * @author Robert Down <robertdown@live.com>
 * @copyright Copyright (c) 2017-2022 Robert Down
 */
class Kernel
{
    /** @var ContainerBuilder */
    private $container;

    public function __construct(private readonly ?EventDispatcherInterface $dispatcher = null)
    {
        $this->prepareContainer();
    }

    /**
     * Setup the initial container
     */
    private function prepareContainer()
    {
        if (!$this->container) {
            $builder = new ContainerBuilder(new ParameterBag());
            $builder->addCompilerPass(new RegisterListenersPass());
            $definition = new Definition(EventDispatcher::class, [new Reference('service_container')]);
            if (!empty($this->dispatcher)) {
                $definition->setSynthetic(true);
            }
            $definition->setPublic(true);
            $builder->setDefinition('event_dispatcher', $definition);

            $builder->setDefinition(
                FileStorageConfig::class,
                (new Definition(FileStorageConfig::class))
                    ->setFactory([FileStorageConfig::class, 'fromEnvironment'])
            );
            $builder->setDefinition(
                S3ClientInterface::class,
                (new Definition(S3ClientInterface::class, [new Reference(FileStorageConfig::class)]))
                    ->setFactory([S3ClientFactory::class, 'create'])
            );
            $builder->setDefinition(
                S3FileStorage::class,
                new Definition(S3FileStorage::class, [
                    new Reference(S3ClientInterface::class),
                    new Reference(FileStorageConfig::class),
                    new Reference(SystemLogger::class),
                ])
            );
            $builder->setAlias(FileStorageInterface::class, S3FileStorage::class)->setPublic(true);
            $builder->setDefinition(S3ObjectKeyGenerator::class, (new Definition(S3ObjectKeyGenerator::class))->setPublic(true));
            $builder->setDefinition(SqlFileMetadataRepository::class, new Definition(SqlFileMetadataRepository::class));
            $builder->setAlias(FileMetadataRepositoryInterface::class, SqlFileMetadataRepository::class);
            $builder->setDefinition(
                FileMetadataService::class,
                (new Definition(FileMetadataService::class, [
                    new Reference(FileMetadataRepositoryInterface::class),
                    new Reference(FileStorageConfig::class),
                ]))->setPublic(true)
            );
            $builder->setAlias(FileMetadataServiceInterface::class, FileMetadataService::class)
                ->setPublic(true);
            $builder->setDefinition(
                FileUploadValidator::class,
                (new Definition(FileUploadValidator::class, [
                    new Reference(FileStorageConfig::class),
                ]))->setPublic(true)
            );
            $builder->setAlias(FileUploadValidatorInterface::class, FileUploadValidator::class)
                ->setPublic(true);
            $builder->setDefinition(
                SqlPatientDocumentRecordRepository::class,
                new Definition(SqlPatientDocumentRecordRepository::class)
            );
            $builder->setAlias(
                PatientDocumentRecordRepositoryInterface::class,
                SqlPatientDocumentRecordRepository::class
            );
            $builder->setDefinition(
                PatientDocumentStorageService::class,
                (new Definition(PatientDocumentStorageService::class, [
                    new Reference(FileStorageInterface::class),
                    new Reference(FileMetadataServiceInterface::class),
                    new Reference(FileUploadValidatorInterface::class),
                    new Reference(S3ObjectKeyGenerator::class),
                    new Reference(PatientDocumentRecordRepositoryInterface::class),
                    new Reference(SystemLogger::class),
                ]))->setPublic(true)
            );
            $builder->setDefinition(SystemLogger::class, new Definition(SystemLogger::class));

            $builder->compile();
            $this->container = $builder;
            if (!empty($this->dispatcher)) {
                $this->container->set('event_dispatcher', $this->dispatcher);
            }
        }
    }

    /**
     * Return true if the environment variable OPENEMR__ENVIRONMENT is set to dev.
     *
     * @return bool
     */
    public function isDev()
    {
        return (($_ENV['OPENEMR__ENVIRONMENT'] ?? '') === 'dev') ? true : false;
    }

    /**
     * Get the Service Container
     *
     * @return ContainerInterface
     */
    public function getContainer(): ContainerInterface
    {
        if (!$this->container) {
            $this->prepareContainer();
        }

        return $this->container;
    }

    /**
     * Get the Event Dispatcher
     *
     * @return EventDispatcherInterface
     * @throws \Exception
     */
    public function getEventDispatcher(): EventDispatcherInterface
    {
        if ($this->container) {
            /** @var EventDispatcherInterface $dispatcher */
            return $this->container->get('event_dispatcher');
        } else {
            throw new \Exception('Container does not exist');
        }
    }
}
