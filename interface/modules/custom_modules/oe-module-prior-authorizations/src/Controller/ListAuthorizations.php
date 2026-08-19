<?php

/*
 *  package OpenEMR
 *  link    https://www.open-emr.org
 *  author  Sherwin Gaddis <sherwingaddis@gmail.com>
 *  Copyright (c) 2022.
 *  All Rights Reserved
 */

namespace Juggernaut\OpenEMR\Modules\PriorAuthModule\Controller;

class ListAuthorizations
{
    private int $pid;

    /**
     * @param int $pid
     */
    public function setPid(int $pid): void
    {
        $this->pid = $pid;
    }

    // public function getAllAuthorizations(): false|array|\ADORecordSet_mysqli
    // {
    //     $sql = "SELECT *
    //                   FROM module_prior_authorizations
    //                   WHERE pid = ? ORDER BY `start_date` DESC";
                    
    //     // return sqlStatement($sql, [$this->pid]);
    //     $result = sqlStatement($sql, [$this->pid]);

    //     if (!$result) {
    //         return false;
    //     }

    //     $authorizations = [];
    //     while ($row = sqlFetchArray($result)) {
    //         $authorizations[] = $row;
    //     }

    //     return $authorizations;
    // }

    public function getAllAuthorizations($pid): false|array|\ADORecordSet_mysqli
    {
        $sql = "SELECT
                    cds_hooks_crd_status.status as pa_status,
                    cds_hooks_crd_status.created_at as start_date,
                    cds_hooks_crd_status.dtr_launch_url,
                    cds_hooks_crd_status.resource_id,
                    cds_hooks_crd_status.authorization_number,
                    procedure_order_code.procedure_code as code,
                    procedure_order_code.procedure_name as name,
                    procedure_order_code.diagnoses as icd10,
                    procedure_order.encounter_id
                FROM cds_hooks_crd_status
                INNER JOIN procedure_order_code
                    ON cds_hooks_crd_status.order_id = procedure_order_code.procedure_order_id
                INNER JOIN procedure_order
                    ON procedure_order_code.procedure_order_id = procedure_order.procedure_order_id
                WHERE procedure_order.patient_id = ?
                ORDER BY cds_hooks_crd_status.created_at DESC";

        $result = sqlStatement($sql, [$pid]);

        if (!$result) {
            return false;
        }

        $authorizations = [];
        while ($row = sqlFetchArray($result)) {
            $authorizations[] = $row;
        }

        return $authorizations;
    }

    private static function getAuthsFromModulePriorAuth(): false|array
    {
        $sql = "SELECT auth_num FROM module_prior_authorizations WHERE pid = ?";
        $auths = sqlStatement($sql, [$_SESSION['pid'] ?? null]);
        $auth_array = [];
        while ($row = sqlFetchArray($auths)) {
            $auth_array[] = $row['auth_num'];
        }
        return $auth_array;
    }

    /**
     * @return void
     * this method is to back populate the module table in case just uses the prior auth form
     * or they have already been using the misc billing options
     * this is a silent function
     */
    public static function insertMissingAuthsFromForm(): void
    {
        $formsAuths = self::formPriorAuth();
        $formMiscBilling = self::formMiscBilling();
        $array_merger = array_push($formsAuths, $formMiscBilling) ?? null;
        $moduleAuths = self::getAuthsFromModulePriorAuth() ?? null;
        if (is_array($moduleAuths) && is_array($array_merger)) {
            $insertArray = array_diff($moduleAuths, $array_merger);

            if (!empty($insertArray)) {
                foreach ($insertArray as $auth) {
                    $isinstalled = sqlQuery("SELECT 1 FROM `form_prior_auth` LIMIT 1");
                    if ($isinstalled !== false) {
                        $getinfo = sqlQuery("SELECT date_from, date_to FROM `form_prior_auth` WHERE `prior_auth_number` = ? ORDER BY `id` DESC LIMIT 1 ", [$auth]);
                    }
                    if (!empty($getinfo['date_from'])) {
                        $saveInfoWithDate = "INSERT INTO `module_prior_authorizations` SET `id` = '', `pid` = ?, `auth_num` = ?, `start_date` = ?, `end_date` = ?";
                        $bindArray = [$_SESSION['pid'], $auth, $getinfo['date_from'], $getinfo['date_to']];
                        sqlStatement($saveInfoWithDate, $bindArray);
                    } elseif (!empty($auth)) {
                        $saveInfoWithDate = "INSERT INTO `module_prior_authorizations` SET `id` = '', `pid` = ?, `auth_num` = ?";
                        $bindArray = [$_SESSION['pid'], $auth];
                        sqlStatement($saveInfoWithDate, $bindArray);
                    }
                }
            }
        }
    }
    /**
     * @return array
     * from form prior auth
     */
    private static function formPriorAuth(): false|array
    {
        $doesExist = sqlQuery("SELECT table_name FROM information_schema.tables WHERE table_name = 'form_form_prior_auth'");
        $auths_array = [];
        if (!empty($doesExist)) {
            $sql = "select prior_auth_number from form_prior_auth where pid = ?";
            $auths = sqlStatement($sql, [$_SESSION['pid']]);
            while ($row = sqlFetchArray($auths)) {
                $auths_array[] = $row['prior_auth_number'];
            }
            return $auths_array;
        }
        return $auths_array;
    }

    /**
     * @return array
     */
    private static function formMiscBilling(): array
    {
        $sql = "select prior_auth_number from form_misc_billing_options where pid = ?";
        $auths = sqlStatement($sql, [$_SESSION['pid'] ?? null]);
        $auths_array = [];
        while ($row = sqlFetchArray($auths)) {
            $auths_array[] = $row['prior_auth_number'];
        }
        return $auths_array;
    }

    public function findTriwestClients(): array
    {
        $list = [];
        $sql = "SELECT `pid`  FROM `insurance_data` WHERE `provider` LIKE '133' ORDER BY `id` ASC";
        $load = sqlStatement($sql);
        while ($row = sqlFetchArray($load)) {
            $list[] = $row['pid'];
        }
        return $list;
    }
}
