<?php
namespace vanguardly\mail_quota_count;

require_once('hooksCtrl.php');

// Add new Client to the Update List Database
Controller::createClient();

// Get Mail and HostData Quata Usage
Controller::checkQuotas();

// Update Mail and HostData Quata Usage
Controller::updateQuotas();
