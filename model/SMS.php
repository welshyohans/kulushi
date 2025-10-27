<?php
require_once __DIR__ . '/../load_env.php';
loadEnv(__DIR__ . '/../.env');
class SMS{
    
    public function sendSms($tt,$mm){ /** We use php cURL for the samples **/

 /** We use php cURL for the samples **/
    $ch = curl_init();
    // base url
	$url = 'https://api.afromessage.com/api/send';
	$token = getenv('AFROMESSAGE_API_TOKEN');
    $from = '';
    $sender = 'Merkato Pro';
	$to = $tt;
	$message = $mm;
	$callback = '';
    // request body
	$body = array("from" => $from,"sender" => $sender,"to" => $to,"message" => $message,"callback"=>$callback);
	
    /** configure request **/
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    /** request headers **/
	$headers = array();
	$headers[] = 'Authorization: Bearer '.$token;
	$headers[] = 'Content-Type: application/json';
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // send request
	$result = curl_exec($ch);

    /** handle response **/
	if (curl_errno($ch)) {
        /** general http error **/
		echo 'Error:' . curl_error($ch);
    } else {	
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		switch ($http_code) {
	    case 200:
                /** Inpsect `acknowledge` node and see if response is error or success **/
				$data = json_decode($result,true);
				if ($data['acknowledge'] == 'success') {
				//	echo "Api success";
                }else{
				//	echo "Api failure";
                }
				break;
	    default:
          /** Other API error ... mostly authorization related. Inpsect response body for details. **/
	      //echo 'Other HTTP Code: ', $http_code;
        }
    }
    /** finish **/
	curl_close ($ch);
    }
    
    
    
     public function sendBulkSms($tt,$mm){
             /** We use php cURL for the samples **/
    $ch = curl_init();
    // base url
	$url = 'https://api.afromessage.com/api/bulk_send';
	$token = getenv('AFROMESSAGE_API_TOKEN');
    $from = 'e80ad9d8-adf3-463f-80f4-7c4b39f7f164';
    $sender = 'Merkato Pro';
	$to = ['+251943080871','+251930694101','+251979149445','+251921923976','+251921538686','+251984722935','+251979300009','+251932219305','+2519941080475','+251951075409','+251943090921','+251911771486','+251922449334'];
	$message = 'ከጥቂት ሰዓት ቦኋላ ወደ ሳሪስ እቃ እናደርሳለን። የምትፈልጉት እቃ ካለ ማዘዝ ትችላላቹ።';   
	$camp = 'Fist campain';
    $scb = '';
    $ccb = '';
    // request body
	$body = array("from" => $from,"sender" => $sender,"to" => $to,"message" => $message,"campaign"=>$camp,"statusCallback"=>$scb,"createCallback"=>$ccb);
	
    /** configure request **/
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    /** request headers **/
	$headers = array();
	$headers[] = 'Authorization: Bearer '.$token;
	$headers[] = 'Content-Type: application/json';
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // send request
	$result = curl_exec($ch);

    /** handle response **/
	if (curl_errno($ch)) {
        /** general http error **/
		echo 'Error:' . curl_error($ch);
    } else {	
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		switch ($http_code) {
	    case 200:
                /** Inpsect `acknowledge` node and see if response is error or success **/
				$data = json_decode($result,true);
				if ($data['acknowledge'] == 'success') {
					echo "Api success";
                }else{
					echo "Api failure";
                }
				break;
	    default:
          /** Other API error ... mostly authorization related. Inpsect response body for details. **/
	      echo 'Other HTTP Code: ', $http_code;
        }
    }
    /** finish **/
	curl_close ($ch);
     }
     
     public function addressBasedSms($phones,$m){
         
             /** We use php cURL for the samples **/
    $ch = curl_init();
    // base url
	$url = 'https://api.afromessage.com/api/bulk_send';
	$token = getenv('AFROMESSAGE_API_TOKEN');
    $from = 'e80ad9d8-adf3-463f-80f4-7c4b39f7f164';
    $sender = 'Merkato Pro';
	$to = $phones;
	$message = $m;  
	$camp = 'Fist campain';
    $scb = '';
    $ccb = '';
    // request body
	$body = array("from" => $from,"sender" => $sender,"to" => $to,"message" => $message,"campaign"=>$camp,"statusCallback"=>$scb,"createCallback"=>$ccb);
	
    /** configure request **/
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    /** request headers **/
	$headers = array();
	$headers[] = 'Authorization: Bearer '.$token;
	$headers[] = 'Content-Type: application/json';
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // send request
	$result = curl_exec($ch);

    /** handle response **/
	if (curl_errno($ch)) {
        /** general http error **/
		echo 'Error:' . curl_error($ch);
    } else {	
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		switch ($http_code) {
	    case 200:
                /** Inpsect `acknowledge` node and see if response is error or success **/
				$data = json_decode($result,true);
				if ($data['acknowledge'] == 'success') {
					echo "Api success";
                }else{
					echo "Api failure";
                }
				break;
	    default:
          /** Other API error ... mostly authorization related. Inpsect response body for details. **/
	      echo 'Other HTTP Code: ', $http_code;
        }
    }
    /** finish **/
	curl_close ($ch);
     
     }
    
}



?>