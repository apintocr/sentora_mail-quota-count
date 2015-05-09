<?php
namespace vanguardly\mail_quota_count;

use fs_filehandler;
use fs_director;
use ctrl_options; // ??
use ctrl_users;
use PDO;

/**
 * @copyright António Pinto (apinto@vanguardly.com)
 *
 * @package Mail Quota Count
 * @subpackage modules
 * @author António Pinto (apinto@vanguardly.com)
 * @link http://vanguardly.com/
 * @license GPL (http://www.gnu.org/licenses/gpl.html)
 */

class Controller
{

    public function updateQuotas()
    {
        global $zdbh;

        /*
         * Calculate the home directory size for each 'active' user account on the server.
         * Taken and modified from /etc/sentora/panel/modules/zpx_core_module/hooks/OnDaemonRun.hook.php
         */

        $userssql = $zdbh->query("SELECT ac_id_pk, ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NULL");

        echo fs_filehandler::NewLine() . "START Mail Quota Count Updating Quotas..." . fs_filehandler::NewLine();

        while ($userdir = $userssql->fetch()) {
            // $homedirectory = ctrl_options::GetSystemOption('hosted_dir') . $userdir['ac_user_vc'];
            // if (fs_director::CheckFolderExists($homedirectory)) {
            //     $size = fs_director::GetDirectorySize($homedirectory);
            // } else {
            //     $size = 0;
            // }

            $mailusagers = $zdbh->prepare("SELECT * FROM x_mailusage WHERE ac_user_vc= :ac_user_vc");
            $mailusagers->bindParam(':ac_user_vc', $userdir['ac_user_vc']);
            $mailusagers->execute();
            if ($mailuse = $mailusagers->fetch()) {
                $size = $mailuse['mailusage'] + $mailuse['webusage'];

            } else {
                $size = 0;
            }

            $currentuser = ctrl_users::GetUserDetail($userdir['ac_id_pk']);
            $numrows = $zdbh->prepare("SELECT COUNT(*)
                AS total
                FROM x_bandwidth
                WHERE bd_month_in = :date AND bd_acc_fk = :ac_id_pk");

            $date = date("Ym");
            $numrows->bindParam(':date', $date);
            $numrows->bindParam(':ac_id_pk', $userdir['ac_id_pk']);
            $numrows->execute();
            $checksql = $numrows->fetch();

            if ($checksql['total'] == 0) {
                $numrows3 = $zdbh->prepare("INSERT INTO x_bandwidth (
                        bd_acc_fk,      bd_month_in,     bd_transamount_bi, bd_diskamount_bi,
                        bd_diskover_in, bd_diskcheck_in, bd_transover_in,   bd_transcheck_in )
                    VALUES (:ac_id_pk,  :date,           0,                 0,
                            0,          0,               0,                 0);");

                $date = date("Ym");
                $numrows3->bindParam(':date', $date);
                $numrows3->bindParam(':ac_id_pk', $userdir['ac_id_pk']);
                $numrows3->execute();
            }

            $updatesql = $zdbh->prepare("UPDATE x_bandwidth SET bd_diskamount_bi = :size WHERE bd_acc_fk =:ac_id_pk");
            $updatesql->bindParam(':size', $size);
            $updatesql->bindParam(':ac_id_pk', $userdir['ac_id_pk']);
            $updatesql->execute();

            $numrows = $zdbh->prepare("SELECT * FROM x_bandwidth WHERE bd_month_in = :date AND bd_acc_fk = :ac_id_pk");
            $date = date("Ym");
            $numrows->bindParam(':date', $date);
            $numrows->bindParam(':ac_id_pk', $userdir['ac_id_pk']);
            $numrows->execute();
            $checksize = $numrows->fetch();

            if ($checksize['bd_diskamount_bi'] > $currentuser['diskquota']) {
                $updatesql = $zdbh->prepare("UPDATE x_bandwidth SET bd_diskover_in = 1 WHERE bd_acc_fk =:ac_id_pk");
                $updatesql->bindParam(':ac_id_pk', $userdir['ac_id_pk']);
                $updatesql->execute();
            } else {
                $updatesql = $zdbh->prepare("UPDATE x_bandwidth SET bd_diskover_in = 0 WHERE bd_acc_fk =:ac_id_pk");
                $updatesql->bindParam(':ac_id_pk', $userdir['ac_id_pk']);
                $updatesql->execute();
            }


            echo "Disk usage for user \"" . $userdir['ac_user_vc'] . "\" is: " . $size
                 . " (" . fs_director::ShowHumanFileSize($size) . ")" . fs_filehandler::NewLine();
        }
        echo "END Calculating disk usage" . fs_filehandler::NewLine();

    }


    public function checkQuotas()
    {
        global $zdbh, $controller;

        echo fs_filehandler::NewLine() .
            "Mail Quota Count Checking Disk and Mail usage..."
            . fs_filehandler::NewLine();

        if (php_uname('s') == 'Windows NT') {
            // Path on Windows
            $vmailpath = "c:/zpanel/bin/hmailserver/data";
        } else {
            // Path on Unix
            $vmailpath = "/var/sentora/vmail";
        }

        $hd_path   = ctrl_options::GetOption('hosted_dir'); // Path to hostdata

        /*
         * DEBUG
         * Uncomment below to echo the variables.
         * Run Daemon: php -q /etc/sentora/panel/bin/daemon.php
         *
         */
        echo fs_filehandler::NewLine() . "hd_path"   . $hd_path . fs_filehandler::NewLine();
        echo fs_filehandler::NewLine() . "vmailpath" . $vmailpath . fs_filehandler::NewLine();

        $acc = $zdbh->query("SELECT ac_id_pk,ac_user_vc FROM x_accounts WHERE ISNULL(ac_deleted_ts)");


        $by_acctname = array();
        $by_id       = array();

        while ($rows = $acc->fetch(PDO::FETCH_ASSOC)) {
            @$by_acctname[$rows['ac_user_vc']] = $rows['ac_id_pk'];
            @$by_id[$rows['ac_id_pk']]         = $rows['ac_user_vc'];
        }

        $rs = $zdbh->query("SELECT * FROM x_mailboxes WHERE ISNULL(mb_deleted_ts)");


        $domain = array();

        while ($rows = $rs->fetch(PDO::FETCH_ASSOC)) {
            list($gh, $dom) =explode("@", $rows['mb_address_vc']);
            @$domain[$dom] = $rows['mb_acc_fk'];
        }


        $mailusage = array();

        foreach ($domain as $k => $v) {

            if (php_uname('s') == 'Windows NT') {
                if (fs_director::CheckFolderExists($vmailpath."/".$k)) {
                    $size = fs_director::GetDirectorySize($vmailpath."/".$k);
                } else {
                    $size = 0;
                }
               @$mailusage[$v] += $size;

            } else {
                @exec("du -c -b ".$vmailpath."/".$k, $output);
                $use = explode("\t", array_pop($output));
               @$mailusage[$v] += $use[0];

            }

        }

        foreach ($mailusage as $k => $v) {
            $zdbh->query('UPDATE x_mailusage SET mailusage='.$v.' WHERE ac_user_vc="'.$by_id[$k].'"');
        }


        $hdusage = array();

        foreach ($by_id as $k => $v) {

            if (php_uname('s') == 'Windows NT') {
                if (fs_director::CheckFolderExists($hd_path."/".$k)) {
                    $size = fs_director::GetDirectorySize($hd_path."/".$k);
                } else {
                    $size = 0;
                }
               @$hdusage[$v] += $size;

            } else {
                echo "A";
                @exec("du -c -b ".$hd_path.$v, $output);
                $use = explode("\t", array_pop($output));
                @$hdusage[$v] += $use[0];

            }
        }



        foreach ($hdusage as $k => $v) {
            $zdbh->query('UPDATE x_mailusage SET webusage='.$v.' WHERE ac_user_vc="'.$k.'"');
        }

        echo "Updated The Mail and Disk Space Usage";
    }

    public function createClient()
    {
        global $zdbh;

        //Get Last Account ID
        $getLastAcc = $zdbh->prepare('SELECT x_accounts.ac_user_vc
            FROM x_accounts
            ORDER BY ac_id_pk DESC
            LIMIT 1');
        $getLastAcc->execute();
        $lastAcc = $getLastAcc->fetch();

        //Add Account to Update List
        $zdbh->query('INSERT INTO x_mailusage(ac_user_vc, mailusage, webusage)
           VALUES ("' . $lastAcc['ac_user_vc'] . '", 0, 0)');
    }

    public function deleteClient()
    {
        global $zdbh;

        //Get Last Account ID
        $getLastDeletedAcc = $zdbh->prepare('SELECT x_accounts.ac_user_vc
            FROM x_accounts
            WHERE  ac_deleted_ts IS NOT NULL
            ORDER BY ac_deleted_ts DESC
            LIMIT 1');
        $getLastDeletedAcc->execute();
        $lastDeletedAcc = $getLastDeletedAcc->fetch();

        //DELETE FROM `sentora_core`.`x_mailusage` WHERE `x_mailusage`.`record_id` = 8
        //Add Account to Update List
        $zdbh->query('DELETE FROM sentora_core.x_mailusage
           WHERE ac_user_vc = "' . $lastDeletedAcc['ac_user_vc'] . '"');
    }
}
