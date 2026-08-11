<?php

/**
 * Minimal admin screen for managing cds_hooks_services rows - lets an
 * admin enter payer credentials (client_id/client_secret/auth_token)
 * through the UI instead of them ever being committed to table.sql or
 * any file in version control.
 *
 * Restricted to users with the 'admin' ACL section, same as other
 * global/admin-only OpenEMR screens.
 *
 * @package   GheitPriorAuth
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Modules\GheitPriorAuth\Service\CdsHooksClient;

if (!AclMain::aclCheckCore('admin', 'super')) {
    die(xlt('Not authorized'));
}

$client = new CdsHooksClient();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $client->registerService(
            trim($_POST['name'] ?? ''),
            trim($_POST['base_url'] ?? ''),
            trim($_POST['service_id'] ?? ''),
            trim($_POST['hook'] ?? 'order-sign'),
            trim($_POST['auth_token'] ?? '') ?: null,
            (int) ($_POST['timeout_seconds'] ?? 3),
            !empty($_POST['enabled']),
            trim($_POST['token_url'] ?? '') ?: null,
            trim($_POST['client_id'] ?? '') ?: null,
            trim($_POST['client_secret'] ?? '') ?: null,
            trim($_POST['fhir_server'] ?? '') ?: null
        );
        $message = xlt('Service added.');
    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        $client->deleteService((int) $_POST['id']);
        $message = xlt('Service deleted.');
    } elseif ($action === 'toggle' && !empty($_POST['id'])) {
        $existing = $client->getServiceById((int) $_POST['id']);
        if ($existing) {
            $client->updateService((int) $_POST['id'], ['enabled' => $existing['enabled'] ? 0 : 1]);
        }
        $message = xlt('Service updated.');
    } elseif ($action === 'update' && !empty($_POST['id'])) {
        $fields = [
            'name'            => trim($_POST['name'] ?? ''),
            'base_url'        => trim($_POST['base_url'] ?? ''),
            'service_id'      => trim($_POST['service_id'] ?? ''),
            'hook'            => trim($_POST['hook'] ?? 'order-sign'),
            'timeout_seconds' => (int) ($_POST['timeout_seconds'] ?? 3),
            'token_url'       => trim($_POST['token_url'] ?? '') ?: null,
            'client_id'       => trim($_POST['client_id'] ?? '') ?: null,
            'tenant_id'       => (int) ($_POST['tenant_id'] ?? 0),
            'service_hash'    => trim($_POST['service_hash'] ?? '') ?: null,
            'fhir_server'     => trim($_POST['fhir_server'] ?? '') ?: null,
        ];
        if (trim($_POST['client_secret'] ?? '') !== '') {
            $fields['client_secret'] = trim($_POST['client_secret']);
        }
        if (trim($_POST['auth_token'] ?? '') !== '') {
            $fields['auth_token'] = trim($_POST['auth_token']);
        }
        $client->updateService((int) $_POST['id'], $fields);
        $message = xlt('Service updated.');
    }
}

$editingService = null;
if (!empty($_GET['edit'])) {
    $editingService = $client->getServiceById((int) $_GET['edit']);
}

$services = $client->getAllServices();
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('CDS Hooks Services - CRD Prior Auth'); ?></title>
</head>
<body>
<div class="container mt-3">
    <h2 class="d-flex align-items-center">
        <?php echo xlt('CDS Hooks Services (CRD Prior Auth)'); ?>
        <button type="button" class="btn btn-sm btn-success ml-3"
                onclick="document.getElementById('addServiceForm').classList.toggle('d-none')">
            <?php echo xlt('Add Service'); ?>
        </button>
    </h2>

    <?php if ($message) : ?>
        <div class="alert alert-info"><?php echo text($message); ?></div>
    <?php endif; ?>

    <table class="table table-sm table-bordered">
        <thead>
        <tr>
            <th><?php echo xlt('Name'); ?></th>
            <th><?php echo xlt('Hook'); ?></th>
            <th><?php echo xlt('Base URL'); ?></th>
            <th><?php echo xlt('Tenant ID'); ?></th>
            <th><?php echo xlt('Service Hash'); ?></th>
            <th><?php echo xlt('Service ID'); ?></th>
            <th><?php echo xlt('Auth'); ?></th>
            <th><?php echo xlt('Enabled'); ?></th>
            <th><?php echo xlt('Actions'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($services as $svc) : ?>
            <tr>
                <td><?php echo text($svc['name']); ?></td>
                <td><?php echo text($svc['hook']); ?></td>
                <td><?php echo text($svc['base_url']); ?></td>
                <td><?php echo text($svc['tenant_id']); ?></td>
                <td><?php echo text($svc['service_hash']); ?></td>
                <td><?php echo text($svc['service_id']); ?></td>
                <td><?php echo !empty($svc['token_url']) ? xlt('OAuth2') : xlt('Static token'); ?></td>
                <td><?php echo $svc['enabled'] ? xlt('Yes') : xlt('No'); ?></td>
                <td>
                    <a href="?edit=<?php echo attr_url($svc['id']); ?>" class="btn btn-sm btn-primary">
                        <?php echo xlt('Update'); ?>
                    </a>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?php echo attr($svc['id']); ?>">
                        <button type="submit" class="btn btn-sm btn-secondary">
                            <?php echo $svc['enabled'] ? xlt('Disable') : xlt('Enable'); ?>
                        </button>
                    </form>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('<?php echo xla('Delete this service?'); ?>');">
                        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo attr($svc['id']); ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><?php echo xlt('Delete'); ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($services)) : ?>
            <tr><td colspan="9"><?php echo xlt('No services registered yet.'); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($editingService) : ?>
    <h4><?php echo xlt('Edit Service'); ?>: <?php echo text($editingService['name']); ?></h4>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?php echo attr($editingService['id']); ?>">

        <div class="form-row">
            <div class="form-group col-md-4">
                <label><?php echo xlt('Name'); ?></label>
                <input type="text" name="name" class="form-control" value="<?php echo attr($editingService['name']); ?>" required>
            </div>
            <div class="form-group col-md-2">
                <label><?php echo xlt('Hook'); ?></label>
                <input type="text" name="hook" class="form-control" value="<?php echo attr($editingService['hook']); ?>" required>
            </div>
            <div class="form-group col-md-2">
                <label><?php echo xlt('Timeout (s)'); ?></label>
                <input type="number" name="timeout_seconds" class="form-control" value="<?php echo attr($editingService['timeout_seconds']); ?>">
            </div>
            <div class="form-group col-md-4">
                <label><?php echo xlt('Base URL'); ?></label>
                <input type="text" name="base_url" class="form-control" value="<?php echo attr($editingService['base_url']); ?>" required>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group col-md-2">
                <label><?php echo xlt('Tenant ID'); ?></label>
                <input type="number" name="tenant_id" class="form-control" value="<?php echo attr($editingService['tenant_id']); ?>">
            </div>
            <div class="form-group col-md-2">
                <label><?php echo xlt('Service Hash'); ?></label>
                <input type="text" name="service_hash" class="form-control" value="<?php echo attr($editingService['service_hash']); ?>">
            </div>
            <div class="form-group col-md-2">
                <label><?php echo xlt('Service ID'); ?></label>
                <input type="text" name="service_id" class="form-control" value="<?php echo attr($editingService['service_id']); ?>">
            </div>
            <div class="form-group col-md-6">
                <label><?php echo xlt('OAuth2 Token URL (leave blank to use static token instead)'); ?></label>
                <input type="text" name="token_url" class="form-control" value="<?php echo attr($editingService['token_url'] ?? ''); ?>">
            </div>
            
        </div>

        <div class="form-row">
            <div class="form-group col-md-3">
                <label><?php echo xlt('FHIR Server URL'); ?></label>
                <input type="text" name="fhir_server" class="form-control" value="<?php echo attr($editingService['fhir_server'] ?? ''); ?>">
            </div>
            <div class="form-group col-md-3">
                <label><?php echo xlt('Client ID'); ?></label>
                <input type="text" name="client_id" class="form-control" value="<?php echo attr($editingService['client_id'] ?? ''); ?>">
            </div>
            <div class="form-group col-md-3">
                <label><?php echo xlt('Client Secret (blank to keep current)'); ?></label>
                <input type="password" name="client_secret" class="form-control" autocomplete="new-password">
            </div>
            <div class="form-group col-md-3">
                <label><?php echo xlt('Static Auth Token (no Token URL)'); ?></label>
                <input type="password" name="auth_token" class="form-control" autocomplete="new-password">
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><?php echo xlt('Save Changes'); ?></button>
        <a href="?" class="btn btn-secondary"><?php echo xlt('Cancel'); ?></a>
    </form>
    <?php endif; ?>

    <div id="addServiceForm" class="d-none mt-3">
        <h4><?php echo xlt('Register New Service'); ?></h4>
        <form method="post" class="form">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label><?php echo xlt('Name'); ?></label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group col-md-2">
                    <label><?php echo xlt('Hook'); ?></label>
                    <input type="text" name="hook" class="form-control" value="order-sign" required>
                </div>
                <div class="form-group col-md-2">
                    <label><?php echo xlt('Timeout (s)'); ?></label>
                    <input type="number" name="timeout_seconds" class="form-control" value="3">
                </div>
                <div class="form-group col-md-4">
                    <label><?php echo xlt('Base URL'); ?></label>
                    <input type="text" name="base_url" class="form-control"
                           placeholder="https://payer.devhcp.com" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-2">
                    <label><?php echo xlt('Tenant ID'); ?></label>
                    <input type="number" name="tenant_id" class="form-control" placeholder="1" required>
                </div>
                <div class="form-group col-md-2">
                    <label><?php echo xlt('Service Hash'); ?></label>
                    <input type="text" name="service_hash" class="form-control" placeholder="mlqlgapj" required>
                </div>
                <div class="form-group col-md-2">
                    <label><?php echo xlt('Service ID'); ?></label>
                    <input type="text" name="service_id" class="form-control" placeholder="fxwlzsvc" required>
                </div>
                <div class="form-group col-md-6">
                    <label><?php echo xlt('OAuth2 Token URL(leave blank to use static token instead)'); ?></label>
                    <input type="text" name="token_url" class="form-control"
                           placeholder="https://payer.devhcp.com/oauth/token">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-3">
                    <label><?php echo xlt('FHIR Server URL'); ?></label>
                    <input type="text" name="fhir_server" class="form-control"
                        placeholder="https://payer.devhcp.com/6/unmdiedh">
                </div>
                <div class="form-group col-md-3">
                    <label><?php echo xlt('Client ID'); ?></label>
                    <input type="text" name="client_id" class="form-control">
                </div>
                <div class="form-group col-md-3">
                    <label><?php echo xlt('Client Secret'); ?></label>
                    <input type="password" name="client_secret" class="form-control" autocomplete="new-password">
                </div>
                <div class="form-group col-md-3">
                    <label><?php echo xlt('Static Auth Token (no Token URL)'); ?></label>
                    <input type="password" name="auth_token" class="form-control" autocomplete="new-password">
                </div>
            </div>

            <div class="form-group form-check">
                <input type="checkbox" name="enabled" class="form-check-input" id="enabled" checked>
                <label class="form-check-label" for="enabled"><?php echo xlt('Enabled'); ?></label>
            </div>

            <button type="submit" class="btn btn-primary"><?php echo xlt('Add Service'); ?></button>
            <a href="?" class="btn btn-secondary"><?php echo xlt('Cancel'); ?></a>
        </form>
    </div>

    <!-- <p class="text-muted mt-3">
        <?php echo xlt('Note: client_secret and auth_token are stored in the cds_hooks_services table. Restrict DB access accordingly; encryption-at-rest for this column is recommended before production use.'); ?>
    </p> -->
</div>
</body>
</html>