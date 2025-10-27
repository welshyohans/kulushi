<?php


include_once '../../config/Database.php';
include_once '../../model/FCM.php';



//$body = $data['message'];
$fcmCode = "eXV_d2fzR8OBcRfz6x3KTj:APA91bFDSK2p1uIKzp6gFNpR_v47TGOsfX2_IofHjY7hjigi-qtWYikPD9mCLTK5jDeBGKyv8Nr_fTgnFv_uMj5jMy3ZLq7YLXNuJsN9JVjcT7A19F97e_0";
$title = "me i am title";
//$isNotifable = $data['is_notifable'];
//$goodsIdList = $data['goodsIdList'];




    
  $fcm = new FCMService('from-merkato', '../../model/mp.json');
  //$notificationDetail = '1$0&'.$title.'&'.$body.'&no|'.$goodsIdList;
  $result = $fcm->sendFCM($fcmCode, $title, "notification body");

echo $result;



?>