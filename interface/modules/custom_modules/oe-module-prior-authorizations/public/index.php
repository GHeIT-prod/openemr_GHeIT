<?php

/*
 *  package OpenEMR
 *  link    https://www.open-emr.org
 *  author  Sherwin Gaddis <sherwingaddis@gmail.com>
 *  Copyright (c) 2022.
 *  All Rights Reserved
 */

require_once dirname(__FILE__, 5) . "/globals.php";
require_once dirname(__DIR__) . '/src/Controller/ListAuthorizations.php';

use Juggernaut\OpenEMR\Modules\PriorAuthModule\Controller\AuthorizationService;
use Juggernaut\OpenEMR\Modules\PriorAuthModule\Controller\ListAuthorizations;
use OpenEMR\Core\Header;
use OpenEMR\Common\Csrf\CsrfUtils;

require_once dirname(__DIR__, 5) . '/vendor/autoload.php';

$pid = $_SESSION['pid'] ?? null;
function isValid($date, $format = 'Y-m-d'): bool
{
    $dt = DateTime::createFromFormat($format, $date);
    return $dt && $dt->format($format) === $date;
}

if (!empty($_POST['token'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["token"])) {
        CsrfUtils::csrfNotVerified();
    }

    $postStartDate = DateToYYYYMMDD($_POST['start_date']);
    $startDate = isValid($postStartDate) === true ? $postStartDate : $_POST['start_date'];

    $postEndDate = DateToYYYYMMDD($_POST['end_date']);
    $endDate = isValid($postEndDate) === true ? $postEndDate : $_POST['end_date'];

    $postData = new AuthorizationService();
    $rawId = $_POST['id'] ?? null;
    $rawId = (ctype_digit((string)$rawId)) ? (int)$rawId : null;
    $postData->setId($rawId);
    $postData->setPid($pid);
    $postData->setAuthNum($_POST['authorization']);
    $postData->setInitUnits($_POST['units']);
    $postData->setStartDate($startDate);
    $postData->setEndDate($endDate);
    $postData->setCpt($_POST['cpts']);
    $postData->storeAuthorizationInfo();
}

$listData = new ListAuthorizations();
$listData->setPid($pid);
$authList = $listData->getAllAuthorizations($pid);

function formatTimeInStatus(?string $createdAt): string
{
    if (empty($createdAt)) {
        return '—';
    }

    $created = strtotime($createdAt);
    if ($created === false) {
        return '—';
    }

    $diffSeconds = time() - $created;
    if ($diffSeconds < 0) {
        $diffSeconds = 0;
    }

    $days = intdiv($diffSeconds, 86400);
    $hours = intdiv($diffSeconds % 86400, 3600);
    $minutes = intdiv($diffSeconds % 3600, 60);

    if ($days > 0) {
        return $days . 'd ' . $hours . 'h';
    }

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }

    return $minutes . 'm';
}

const TABLE_TD = "</td><td>";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo xlt('Add Prior Auth'); ?></title>
    <?php Header::setupHeader(['common', 'datetime-picker']) ?>

    <script>
        $(function () {
            $('.datepicker').datetimepicker({
                <?php $datetimepicker_timepicker = false; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = true; ?>
                <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
                <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
            });
        })

        function refreshme() {
            top.restoreSession();
            location.reload();
        }
    </script>
</head>
<body>
    <div class="container">
        <div class="m-4">
            <span style="font-size: xx-large; padding-right: 20px"><?php echo xlt('Prior Authorization Manager'); ?></span>
            <a href="../../../../patient_file/summary/demographics.php" onclick="top.restoreSession()"
                title="<?php echo xla('Go Back') ?>">
                <i id="advanced-tooltip" class="fa fa-undo fa-2x" aria-hidden="true"></i></a>

        </div>
        <div class="m-4">
            <?php if (empty($pid)) {
                echo xlt("You must be in a patients Chart to enter this information");
                die;
            } ?>
            <div class="m-3">
                <h3><?php echo xlt('Enter new authorization'); ?></h3>
            </div>
            <form id="theform" method="post" action="index.php" onsubmit="top.restoreSession()">
                <input type="hidden" name="token" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
                <input type="hidden" id="id" name="id" value="">
                <div class="form-row">
                    <div class="col">
                        <input class="form-control" id="authorization" name="authorization" value="" placeholder="<?php echo xla('Authorization Number') ?>">
                    </div>
                    <div class="col">
                        <input class="form-control" id="units" name="units" value="" placeholder="<?php echo xla('Units') ?>">
                    </div>
                    <div class="col">
                        <input class="form-control datepicker" id="start_date" name="start_date" value="" placeholder="<?php echo xla('Start Date') ?>" readonly>
                    </div>
                    <div class="col">
                        <input class="form-control datepicker" id="end_date" name="end_date" value="" placeholder="<?php echo xla('End Date') ?>" readonly>
                    </div>
                </div>
                <div class="form-row">
                    <div class="col my-1">
                        <input class="form-control" id="cpts" name="cpts" value="" placeholder="<?php echo xla('CPTs') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="col">
                        <input class="form-control btn btn-primary" type="submit" value="<?php echo xla('Save') ?>">
                    </div>
                </div>
            </form>
        </div>
        <!-- <div class="m-4">
            <table class="table table-striped">
                <caption><?php echo xla('Display of authorization code'); ?></caption>
                <tr>
                    <th scope="col"><?php echo xlt('Authorization Number'); ?></th>
                    <th scope="col"><?php echo xlt('Allocated Units'); ?></th>
                    <th scope="col"><?php echo xlt('Remaining Units'); ?></th>
                    <th scope="col"><?php echo xlt('Start Date'); ?></th>
                    <th scope="col"><?php echo xlt('End Date'); ?></th>
                    <th scope="col"><?php echo xlt('CPTs'); ?></th>
                    <th scope="col"></th>
                    <th scope="col"></th>
                </tr>
                <?php
                if (!empty($authList)) {
                    while ($iter = sqlFetchArray($authList)) {
                        $editData = json_encode($iter);
                        $used = AuthorizationService::getUnitsUsed($iter['auth_num'], $iter['pid'], $iter['cpt'], $iter['start_date'], $iter['end_date']);
                        $remaining = $iter['init_units'] - $used;
                        print "<tr><td>";
                        print text($iter['auth_num']);
                        print TABLE_TD . text($iter['init_units']);
                        print TABLE_TD . text($remaining);
                        print TABLE_TD . text($iter['start_date']);
                        if ($iter['end_date'] == '0000-00-00') {
                            print TABLE_TD;
                        } else {
                            print TABLE_TD . text($iter['end_date']);
                        }
                        print TABLE_TD . text($iter['cpt']);
                        print TABLE_TD . " <button class='btn btn-primary' onclick=getRowData(" . attr_js($iter['id']) . ")>" . xlt('Edit') . "</button>
                        <input type='hidden' id='" . attr_js($iter['id']) . "' value='" . attr($editData) . "' ></td>";
                        print "<td><a class='btn btn-danger' href='#' onclick=removeEntry(" . attr_js($iter['id']) . ")>" . xlt('Delete') . "</a></td>";

                        print "</tr>";
                    }
                }
                ?>
            </table>
        </div>
        &copy; <?php echo date('Y') . " Juggernaut Systems Express" ?> -->

        <div class="m-4">
            <h3 class="mb-3"><?php echo xlt('Prior Authorization Queue'); ?></h3>
            <div class="table-responsive">
                <table class="table table-bordered table-sm bg-white mb-0">
                    <tr style="background-color: var(--gray200);">
                        <th><?php echo xlt('Encounter ID'); ?></th>
                        <th><?php echo xlt('Auth #'); ?></th>
                        <th><?php echo xlt('PA Status'); ?></th>
                        <th><?php echo xlt('CPT/HCPCS'); ?></th>
                        <th><?php echo xlt('ICDs'); ?></th>
                        <th><?php echo xlt('Allotted / Remaining'); ?></th>
                        <th><?php echo xlt('Start Date'); ?></th>
                        <th><?php echo xlt('Time in Status'); ?></th>
                        <th><?php echo xlt('Payload'); ?></th>
                        <th><?php echo xlt('Response'); ?></th>
                        <th><?php echo xlt('Print'); ?></th>
                        <th><?php echo xlt('Action'); ?></th>
                    </tr>
                    <?php
                    if (!empty($authList)) {
                        foreach ($authList as $iter) {
                            $used = AuthorizationService::getUnitsUsed($iter['auth_num'], $iter['pid'], $iter['cpt'], $iter['start_date'], $iter['end_date']);
                            $remaining = $iter['init_units'] - $used;

                            // TODO: these fields aren't in $iter yet — wire up once the schema/service exposes them.
                            $encounterId   = $iter['encounter_id'] ?? '—';
                            $icds          = explode(':', $iter['icd10'])[1] ?? '—';
                            $status        = $iter['pa_status'] ?? 'pending_review';   // e.g. 'complete' | 'pending_review'
                            $timeInStatus  = $iter['time_in_status'] ?? '—';
                            $payloadUrl    = $iter['payload_url'] ?? '#';
                            $responseUrl   = $iter['response_url'] ?? '#';
                            $canPrint      = !empty($iter['can_print']);
                            $actionText    = $iter['action_note'] ?? '—';
                            $cpt           = explode(':', $iter['code'])[1] ?? '—';

                            $statusLabels = [
                                'complete'       => ['text' => xl('Complete'),       'bg' => '#e6f4ea', 'color' => '#1e7e34'],
                                'pending_review' => ['text' => xl('Pending Review'), 'bg' => '#e8f0fe', 'color' => '#1a56db'],
                            ];
                            $statusStyle = $statusLabels[$status] ?? $statusLabels['pending_review'];
                            ?>
                            <tr>
                                <td><?php echo text($encounterId); ?></td>
                                <td><?php echo text($iter['authorization_number']); ?></td>
                                <td>
                                    <span class="badge badge-pill" style="background:<?php echo attr($statusStyle['bg']); ?>;color:<?php echo attr($statusStyle['color']); ?>;padding:6px 14px;font-weight:600;">
                                        <?php echo text($statusStyle['text']); ?>
                                    </span>
                                </td>
                                <td><?php echo text($cpt); ?></td>
                                <td><?php echo text($icds); ?></td>
                                <td><?php echo text($iter['init_units']) . ' / ' . text($remaining); ?></td>
                                <!-- <td><?php echo text($iter['start_date']); ?></td> -->
                                 <td><?php echo attr(substr($iter['start_date'], 0, 10)); ?></td>
                                <td>
                                    <span class="badge badge-pill" style="background:#eee;color:#555;padding:6px 12px;">
                                        <?php echo text(formatTimeInStatus($iter['start_date'] ?? null)); ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo attr($payloadUrl); ?>" target="_blank">
                                        <?php echo xlt('View Payload'); ?>
                                    </a>
                                </td>
                                <td>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo attr($responseUrl); ?>" target="_blank">
                                        <?php echo xlt('View Response'); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($canPrint) : ?>
                                        <button type="button" class="btn btn-sm" style="background:#2e8b57;color:#fff;"
                                                onclick="printAuth(<?php echo attr_js($iter['id']); ?>)">
                                            <?php echo xlt('Print'); ?>
                                        </button>
                                    <?php else : ?>
                                        <button type="button" class="btn btn-sm" style="background:#ccc;color:#777;" disabled>
                                            <?php echo xlt('Print'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?php echo text($actionText); ?></td>
                            </tr>
                            <?php
                        }
                    }
                    ?>
                </table>
            </div>
        </div>
    </div>
    <script>
        function getRowData(jsonData) {
            let dataArray = document.getElementById(jsonData).value;
            const obj = JSON.parse(dataArray);

            document.getElementById('id').value = obj.id;
            document.getElementById('authorization').value = obj.auth_num;
            document.getElementById('start_date').value = obj.start_date;
            document.getElementById('end_date').value = obj.end_date;
            document.getElementById('cpts').value = obj.cpt;
            document.getElementById('units').value = obj.init_units;
        }

        function removeEntry(id) {
            let url = 'deleter.php?id=' + encodeURIComponent(id) + '&csrf_token_form=' + <?php echo js_url(CsrfUtils::collectCsrfToken()); ?>;
            ;
            dlgopen(url, '_blank', 290, 290, '', 'Delete Entry', {
                buttons: [
                    {text: <?php echo xlj('Done') ?>, style: 'danger btn-sm', close: true}
                ],
                onClosed: 'refreshme'
            })
        }

        function printAuth(id) {
            // TODO: wire up to real print/report endpoint for this authorization.
            top.restoreSession();
            window.open('print_auth.php?id=' + encodeURIComponent(id), '_blank', 'width=800,height=900');
        }
    </script>

</body>
</html>
