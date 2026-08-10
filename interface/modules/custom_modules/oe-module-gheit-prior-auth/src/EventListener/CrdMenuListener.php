<?php

/**
 * Adds "CDS Hooks Services (CRD Prior Auth)" to OpenEMR's left-nav menu,
 * under the Modules parent item, linking to public/admin_services.php.
 *
 * OpenEMR does NOT support declaring custom-module menu entries via
 * openemr.json - there is no such manifest convention. Menu items are
 * added the same way any other event-driven extension point works in
 * OpenEMR: subscribe to OpenEMR\Menu\MenuEvent::MENU_UPDATE, receive the
 * full menu tree, find the parent node you want to hang a new item off
 * of, and append to its `children` array.
 *
 * IMPORTANT - version-specific piece you must verify yourself:
 * the `menu_id` of the "Modules" parent node (looked for in
 * MODULES_PARENT_MENU_IDS below) differs across OpenEMR versions/forks.
 * If your item doesn't show up after enabling the module, see
 * findParentMenuId() below - it will log every top-level menu_id/label it
 * sees so you can identify the right one and add it to the candidates
 * list.
 *
 * @package   GheitPriorAuth
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\GheitPriorAuth\EventListener;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Menu\MenuEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CrdMenuListener implements EventSubscriberInterface
{
    /**
     * Known menu_id values used for the "Modules" parent item across
     * different OpenEMR versions/forks. First match wins. If none match,
     * we log the full top-level list instead of silently doing nothing -
     * check the OpenEMR system log (Administration -> Logs, or the raw
     * PHP error log) for "CrdMenuListener: no known Modules parent found"
     * to see the actual menu_id in your installation, then add it here.
     */
    private const MODULES_PARENT_MENU_IDS = ['modimg', 'mods', 'moduleMenu', 'custom_mod'];

    public static function getSubscribedEvents(): array
    {
        return [
            MenuEvent::MENU_UPDATE => 'onMenuUpdate',
        ];
    }

    public function onMenuUpdate(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        $parent = $this->findParentMenuId($menu);
        if ($parent === null) {
            return;
        }

        $item = new \stdClass();
        $item->label = xl('CDS Hooks Services (CRD Prior Auth)');
        $item->url = '/interface/modules/custom_modules/oe-module-gheit-prior-auth/public/admin_services.php';
        $item->menu_id = 'gheitPriorAuthServices';
        $item->target = 'admin';
        $item->requirement = 0;
        $item->children = [];
        $item->acl_req = ['admin', 'super'];
        $item->global_req = [];

        $parent->children[] = $item;

        $event->setMenu($menu);
    }

    /**
     * Walk the top-level menu items and return the first one whose
     * menu_id matches a known "Modules" parent id. Logs every candidate
     * seen so the real id can be identified if none of the guesses match.
     */
    private function findParentMenuId(array $menu): ?object
    {
        $seen = [];
        foreach ($menu as $item) {
            $menuId = $item->menu_id ?? '';
            $label = $item->label ?? '';
            $seen[] = "{$menuId} ({$label})";

            if (in_array($menuId, self::MODULES_PARENT_MENU_IDS, true)) {
                return $item;
            }
        }

        (new SystemLogger())->debug(
            'CrdMenuListener: no known Modules parent found among top-level menu items',
            ['candidates' => $seen]
        );

        return null;
    }
}
