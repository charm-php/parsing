<?php
$instance = (object) [ "Hei" => "Hopp" ];

var_dump(clone clone $instance);

