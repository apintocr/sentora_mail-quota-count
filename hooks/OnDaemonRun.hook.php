<?php

checkquotas();
updatequotas();

function updatequotas()
{
	global $zdbh;

	/*
	 * Calculate the home directory size for each 'active' user account on the server.
	 * Taken and modified from /etc/sentora/panel/modules/zpx_core_module/hooks/OnDaemonRun.hook.php
	 */
	$userssql = $zdbh->query("SELECT ac_id_pk, ac_user_vc FROM x_accounts WHERE ac_deleted_ts IS NULL");
	echo fs_filehandler::NewLine() . "START Calculating disk Usage for all client accounts.." . fs_filehandler::NewLine();
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
	    if($mailuse = $mailusagers->fetch())
	        {
	            $size = $mailuse['mailusage'] + $mailuse['webusage'];
	        }
	    else {
	        $size = 0;
	    }

	    $currentuser = ctrl_users::GetUserDetail($userdir['ac_id_pk']);
	    $numrows = $zdbh->prepare("SELECT COUNT(*) AS total FROM x_bandwidth WHERE bd_month_in = :date AND bd_acc_fk = :ac_id_pk");
	    $date = date("Ym");
	    $numrows->bindParam(':date', $date);
	    $numrows->bindParam(':ac_id_pk', $userdir['ac_id_pk']);
	    $numrows->execute();
	    $checksql = $numrows->fetch();

	    if ($checksql['total'] == 0) {
	        $numrows3 = $zdbh->prepare("INSERT INTO x_bandwidth (bd_acc_fk, bd_month_in, bd_transamount_bi, bd_diskamount_bi, bd_diskover_in, bd_diskcheck_in, bd_transover_in, bd_transcheck_in ) VALUES (:ac_id_pk,:date,0,0,0,0,0,0);");
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


	    echo "Disk usage for user \"" . $userdir['ac_user_vc'] . "\" is: " . $size . " (" . fs_director::ShowHumanFileSize($size) . ")" . fs_filehandler::NewLine();
	}
	echo "END Calculating disk usage" . fs_filehandler::NewLine();

}


function checkquotas() {
	global $zdbh;

	$vmailpath = "/var/zpanel/vmail";  // Path to vmail
	$hd_path = "/var/zpanel/hostdata"; // Path to hostdata

	// $conn = new mysqli("localhost", "root", "**PasswordGoesHere**", "zpanel_core")
	// or die("Cannot Connect to Database");

    $acc = $zdbh->query("SELECT ac_id_pk,ac_user_vc FROM x_accounts");


	$by_acctname = array();
	$by_id = array();
	while($rows = $acc->fetch(PDO::FETCH_ASSOC))
		{
		@$by_acctname[$rows['ac_user_vc']] = $rows['ac_id_pk'];
		@$by_id[$rows['ac_id_pk']] = $rows['ac_user_vc'];
		}

	// $rs = $conn->query('SELECT * FROM x_mailboxes WHERE ISNULL(mb_deleted_ts)');
    $rs = $zdbh->query("SELECT * FROM x_mailboxes WHERE ISNULL(mb_deleted_ts)");

	$domain = array();
	while($rows = $rs->fetch(PDO::FETCH_ASSOC))
	{
		list($gh, $dom) =explode("@", $rows['mb_address_vc']);
		@$domain[$dom] = $rows['mb_acc_fk'];
	}

	$mailusage = array();
	foreach($domain as $k=>$v)
		{
			@exec("du -c -b ".$vmailpath."/".$k, $output);
			$use = explode("\t", array_pop($output));
			@$mailusage[$v] += $use[0];

		}

	foreach($mailusage as $k=>$v)
	    {
	        $zdbh->query('UPDATE x_mailusage SET mailusage='.$v.' WHERE ac_user_vc="'.$by_id[$k].'"');
	    }

	$hdusage = array();
	foreach($by_id as $k=>$v)
	    {
	        @exec("du -c -b ".$hd_path."/".$v, $output);
	        $use = explode("\t", array_pop($output));
	        @$hdusage[$v] += $use[0];
	    }

	foreach($hdusage as $k=>$v)
	{
	    $zdbh->query('UPDATE x_mailusage SET webusage='.$v.' WHERE ac_user_vc="'.$k.'"');
	}

	echo "Updated The Mail and Disk Space Usage";
}


function DeleteMailboxesForDeletedClient() {
    global $zdbh;
    $deletedclients = array();
    $sql = "SELECT COUNT(*) FROM x_accounts WHERE ac_deleted_ts IS NOT NULL";
    if ($numrows = $zdbh->query($sql)) {
        if ($numrows->fetchColumn() <> 0) {
            $sql = $zdbh->prepare("SELECT * FROM x_accounts WHERE ac_deleted_ts IS NOT NULL");
            $sql->execute();
            while ($rowclient = $sql->fetch()) {
                $deletedclients[] = $rowclient['ac_id_pk'];
            }
        }
    }

    // Include mail server specific file here.
    if (file_exists("modules/mailboxes/hooks/" . ctrl_options::GetSystemOption('mailserver_php') . "")) {
        include("modules/mailboxes/hooks/" . ctrl_options::GetSystemOption('mailserver_php') . "");
    }

    foreach ($deletedclients as $deletedclient) {
//      $result = $zdbh->query("SELECT * FROM x_mailboxes WHERE mb_acc_fk=" . $deletedclient . " AND mb_deleted_ts IS NULL")->Fetch();
        $numrows = $zdbh->prepare("SELECT * FROM x_mailboxes WHERE mb_acc_fk=:deletedclient AND mb_deleted_ts IS NULL");
        $numrows->bindParam(':deletedclient', $deletedclient);
        $numrows->execute();
        $result = $numrows->fetch();
        if ($result) {
            $time = time();
            $sql = $zdbh->prepare("UPDATE x_mailboxes SET mb_deleted_ts=:time WHERE mb_acc_fk=:deletedclient");
            $sql->bindParam(':time', $time);
            $sql->bindParam(':deletedclient', $deletedclient);
            $sql->execute();
        }
    }
}

