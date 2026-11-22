<?php

namespace Tests\Base\Mailbox\Kernel;

use Base\Mailbox\Controller\Client\MailboxController;
use Base\Mailbox\DependencyInjection\MailboxExtension;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * omnipack/mailbox 3.0 presumes no provider: omnipack/office (the attachments' vault) and the persistence bridge
 * (omnibridge/doctrine or omnibridge/eloquent) are suggested. Without office the bundle boots and has no
 * attachments: their entity is not mapped, the controller is given none.
 */
final class WithoutSuggestedPackagesTest extends TestCase
{
    private const SUGGESTED = [
        'omnipack/office' => 'Base\\Office\\',
    ];

    private static function composer(): array
    {
        return json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testTheVaultAndThePersistenceAreSuggestedNotRequired(): void
    {
        $composer = self::composer();
        foreach (['omnipack/office', 'omnibridge/doctrine'] as $package) {
            self::assertArrayNotHasKey($package, $composer['require'], $package.' is not required');
            self::assertArrayHasKey($package, $composer['suggest'], $package.' is suggested');
            self::assertArrayHasKey($package, $composer['require-dev'], 'there for the bundle\'s own suite');
        }
    }

    public function testTheSourceNamesNoPersistence(): void
    {
        $named = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('php' === $file->getExtension() && preg_match_all('/^use (Doctrine\\\\(?:ORM|DBAL|Persistence|Bundle)\\\\|Omnibridge\\\\Doctrine\\\\)\S+;/m', (string) file_get_contents($file->getPathname()), $m)) {
                $named[] = $file->getFilename().': '.implode(', ', $m[0]);
            }
        }
        self::assertSame([], $named);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutTheVaultAttachmentsAreNotMapped(): void
    {
        WithoutSuggestedPackagesKernel::hide(array_values(self::SUGGESTED));
        self::assertSame([], self::prepended(['attachments' => true]), 'attachments asked for, no vault: nothing to map');
        self::assertArrayHasKey('MailboxExtension', self::prepended(['desks' => ['support' => 'ROLE_STAFF'], 'attachments' => true])[0]['orm']['mappings'] ?? [], 'the desks are mapped');
    }

    /** @return list<array<string, mixed>> the configuration given to doctrine */
    private static function prepended(array $config): array
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function getAlias(): string { return 'doctrine'; }
            public function load(array $configs, ContainerBuilder $container): void {}
        });
        $container->registerExtension($extension = new MailboxExtension());
        $container->loadFromExtension('mailbox', $config);
        $extension->prepend($container);

        return $container->getExtensionConfig('doctrine');
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheBundleBootsWithItsCoreOnly(): void
    {
        if (!class_exists('App\\Kernel')) {
            self::markTestSkipped('Needs a host application (its kernel).');
        }
        if ([] !== $dependents = self::dependents(array_keys(self::SUGGESTED))) {
            self::markTestSkipped('The host\'s other packages require what is hidden here: '.implode(', ', $dependents).'.');
        }
        WithoutSuggestedPackagesKernel::hide(array_values(self::SUGGESTED));
        $kernel = new WithoutSuggestedPackagesKernel('test', true);
        ob_start(); // omnibase's cache warmers say what they warm
        try {
            $kernel->boot();
        } finally {
            ob_end_clean();
        }
        try {
            $container = $kernel->getContainer();
            if (!$container->hasParameter('mailbox.per_page')) {
                self::markTestSkipped('The host application does not load omnipack/mailbox.');
            }
            self::assertFalse(interface_exists('Base\\Office\\Share\\AudienceResolverInterface'), 'hidden');
            $services = $container->get('test.service_container');
            self::assertFalse($services->has('Base\\Mailbox\\Attachment\\Office\\VaultAttachments'), 'the vault is omnipack/office\'s');
            self::assertInstanceOf(\stdClass::class, $services->get('mailbox.attachments'), 'no vault, no attachments');
            self::assertInstanceOf(MailboxController::class, $services->get(MailboxController::class));
        } finally {
            $kernel->shutdown();
            (new \Symfony\Component\Filesystem\Filesystem())->remove($kernel->getCacheDir());
        }
    }

    /** @return list<string> the host's installed packages, other than this one, that require one of these */
    private static function dependents(array $packages): array
    {
        $installed = \dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName()).'/installed.json';
        $data = is_file($installed) ? json_decode((string) file_get_contents($installed), true) : [];
        $found = [];
        foreach ($data['packages'] ?? $data as $package) {
            if (\is_array($package) && 'omnipack/mailbox' !== ($package['name'] ?? '') && array_intersect($packages, array_keys($package['require'] ?? []))) {
                $found[] = $package['name'];
            }
        }

        return $found;
    }
}
