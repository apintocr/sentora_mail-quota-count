<?php
namespace vanguardly\mail_quota_count;

require_once('hooksCtrl.php');

// Get Mail and HostData Quata Usage
Controller::checkQuotas();

// Update Mail and HostData Quata Usage
Controller::updateQuotas();
