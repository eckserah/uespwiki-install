<?php
/* Override attempt for global functions */
function wfHostNameAlt() {
	static $host;
		if ( is_null( $host ) ) {
			# Hostname overriding
			global $wgOverrideHostname;
			if ( $wgOverrideHostname !== false ) {
				# Set static and skip any detection
				$host = $wgOverrideHostname;
				return $host;
			}

			if ( function_exists( 'posix_uname' ) ) {
				// This function not present on Windows
				$uname = posix_uname();
			} else {
				$uname = false;
			}
			if ( is_array( $uname ) && isset( $uname['nodename'] ) ) {
				$host = $uname['nodename'];
			} elseif ( getenv( 'COMPUTERNAME' ) ) {
				# Windows computer name
				$host = getenv( 'COMPUTERNAME' );
			} elseif (isset($_SERVER['SERVER_NAME'])) {
			   # This may be a virtual server.
			   $host = $_SERVER['SERVER_NAME'];
			} else {
			   global $wgServerName;
			   $host = $wgServerName;
			}
		}
		return $host;
}
if (function_exists('runkit7_function_redefine')) {
	runkit7_function_redefine('wfHostname','', 'return wfHostNameAlt();');
}