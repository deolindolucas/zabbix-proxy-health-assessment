<?php declare(strict_types = 0);

namespace Modules\ProxyHealthAssessment;

use APP;
use CMenuItem;
use CRoleHelper;
use CWebUser;
use Zabbix\Core\CModule;

/**
 * Registra o Proxy Health Assessment no menu Administration.
 */
class Module extends CModule {

    public function init(): void {
        if (defined(CRoleHelper::class.'::UI_ADMINISTRATION_GENERAL')
                && !CWebUser::checkAccess(CRoleHelper::UI_ADMINISTRATION_GENERAL)) {
            return;
        }

        $administration = APP::Component()->get('menu.main')->find(_('Administration'));

        if ($administration !== null) {
            $administration->getSubMenu()->insertAfter(
                _('General'),
                (new CMenuItem(_('Proxy Health')))->setAction('proxy.health.view')
            );
        }
    }
}
