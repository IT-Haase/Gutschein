<?php declare(strict_types=1);

namespace Jules\CartCouponValueValidation;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class JulesCartCouponValueValidation extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Remove configuration if not uninstalling with keepUserData = true
        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Logic to remove configuration, if any, would go here.
        // For now, we rely on Shopware to handle system config removal for the plugin domain.
    }
}
