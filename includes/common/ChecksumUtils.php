<?php

class PPEC_PhonepeChecksumPG{
	static public function phonepeExpressBuyCalculateChecksum($params, $endpoint, $key, $index) {
		$merged = $params . $endpoint .  $key ;
		$hashedstring = hash('sha256', $merged) ;
		return $hashedstring . "###" . $index ;
	}
}

?>