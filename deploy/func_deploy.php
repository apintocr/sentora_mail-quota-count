<?php

function installModule()
{
    global $zdbh;

    // Create Table "x_mailusage" to Track Mail Quota
    $sql = $zdbh->prepare("CREATE TABLE IF NOT EXISTS x_mailusage (
        record_id  bigint(20)  NOT NULL AUTO_INCREMENT,
        ac_user_vc varchar(20) NOT NULL,
        mailusage  bigint(20)  NOT NULL,
        webusage   bigint(20)  NOT NULL,
        PRIMARY KEY (record_id))
        ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    $sql->execute();

    // Transfer Existing User Account to created Table
    $sql = $zdbh->prepare("INSERT INTO x_mailusage (ac_user_vc)
        SELECT x_accounts.ac_user_vc
        FROM   x_accounts;");
    $sql->execute();

}
