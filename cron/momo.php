<?php

    define("IN_SITE", true);
    require_once(__DIR__.'/../libs/db.php');
    require_once(__DIR__.'/../config.php');
    require_once(__DIR__.'/../libs/helper.php');
    require_once(__DIR__.'/../libs/database/users.php');
    require_once(__DIR__.'/../libs/database/invoices.php');
    $CMSNT = new DB();
    $user = new users();

    if($CMSNT->site('pin_cron') != ''){
        if(empty($_GET['pin'])){
            die('Vui lòng nhập mã PIN');
        }
        if($_GET['pin'] != $CMSNT->site('pin_cron')){
            die('Mã PIN không chính xác');
        }
    }
    /* START CHỐNG SPAM */
    if (time() > $CMSNT->site('check_time_cron')) {
        if (time() - $CMSNT->site('check_time_cron') < 15) {
            die('Thao tác quá nhanh, vui lòng đợi');
        }
    }
    $CMSNT->update("settings", ['value' => time()], " `name` = 'check_time_cron' ");

    queryCancelInvoices();
    curl_get2(base_url('cron/cron.php?pin='.$CMSNT->site('pin_cron')));
    curl_get2(base_url('cron/cron1.php?pin='.$CMSNT->site('pin_cron')));

    
    /* END CHỐNG SPAM */
    if ($CMSNT->site('status_momo') != 1) {
        die('Chức năng đang bảo trì.');
    }
    if ($CMSNT->site('token_momo') == '') {
        die('Thiếu Token Momo');
    }
    $result = curl_get("https://api.sieuthicode.net/historyapimomo/".trim($CMSNT->site('token_momo')));
    $result = json_decode($result, true);
    foreach ($result['momoMsg']['tranList'] as $data) {
        if($data['status'] != 2){
            continue;
        }
        $partnerId      = $data['partnerId'];               // SỐ ĐIỆN THOẠI CHUYỂN
        $comment        = $data['comment'];                 // NỘI DUNG CHUYỂN TIỀN
        $tranId         = $data['tranId'];                  // MÃ GIAO DỊCH
        $partnerName    = $data['partnerName'];             // TÊN CHỦ VÍ
        $amount         = $data['amount'];                  // SỐ TIỀN CHUYỂN
        $user_id        = parse_order_id($comment, $CMSNT->site('prefix_autobank'));         // TÁCH NỘI DUNG CHUYỂN TIỀN
        // XỬ LÝ AUTO SERVER 2
        if($CMSNT->site('sv2_autobank') == 1 && checkAddon(24) == true){
            if($getUser = $CMSNT->get_row(" SELECT * FROM `users` WHERE `id` = '$user_id' ")){
                if($CMSNT->num_rows(" SELECT * FROM `server2_autobank` WHERE `tid` = '$tranId' ") == 0){
                    $insertSv2 = $CMSNT->insert("server2_autobank", array(
                        'tid'               => $tranId,
                        'user_id'           => $getUser['id'],
                        'description'       => $comment,
                        'amount'            => $amount,
                        'received'          => checkPromotion($amount),
                        'create_gettime'    => gettime(),
                        'create_time'       => time()
                    ));
                    if ($insertSv2){
                        $received = checkPromotion($amount);
                        $isCong = $user->AddCredits($getUser['id'], $received, "Nạp tiền tự động qua ví MOMO (#$tranId - $amount - $comment - $partnerId - $partnerName)");
                        if($isCong){

                            /** SEND NOTI CHO ADMIN */
                            $my_text = $CMSNT->site('naptien_notification');
                            $my_text = str_replace('{domain}', $_SERVER['SERVER_NAME'], $my_text);
                            $my_text = str_replace('{username}', $getUser['username'], $my_text);
                            $my_text = str_replace('{method}', 'Ví MOMO server2', $my_text);
                            $my_text = str_replace('{amount}', format_cash($amount), $my_text);
                            $my_text = str_replace('{price}', format_currency($received), $my_text);
                            $my_text = str_replace('{time}', gettime(), $my_text);
                            sendMessAdmin($my_text);

                            echo '[<b style="color:green">-</b>] Xử lý thành công 1 hoá đơn.'.PHP_EOL;
                        }
                    }
                }
            }
        }
        // XỬ LÝ AUTO SERVER 1
        if($CMSNT->site('sv1_autobank') == 1){
            if($CMSNT->num_rows(" SELECT * FROM `invoices` WHERE `description` = '$comment' AND `tid` = '$tranId' ") > 0){
                continue;
            }
            foreach (whereInvoicePending('MOMO', $amount) as $row) {
                if($row['description'] == $comment && $row['tid'] == $tranId){
                    continue;
                }
                if($CMSNT->num_rows(" SELECT * FROM `server2_autobank` WHERE `tid` = '$tranId' AND `description` = '$comment' ") > 0){
                    continue;
                }
                if (isset(explode($row['trans_id'], strtoupper($comment))[1])) {
                    if ($amount >= $row['pay']) {
                        $isUpdate = $CMSNT->update("invoices", [
                            'status'        => 1,
                            'description'   => $comment,
                            'tid'           => $tranId,
                            'update_date'   => gettime(),
                            'update_time'   => time()
                        ], " `id` = '".$row['id']."' ");
                        if ($isUpdate) {
                            $isCong = $user->AddCredits($row['user_id'], $row['amount'], "Thanh toán hoá đơn nạp tiền #".$row['trans_id']);
                            if (!$isCong) {
                                $CMSNT->update("invoices", [
                                'status'  => 0
                                ], " `id` = '".$row['id']."' ");
                            }
                        }
                        /** SEND NOTI CHO ADMIN */
                        $my_text = $CMSNT->site('naptien_notification');
                        $my_text = str_replace('{domain}', $_SERVER['SERVER_NAME'], $my_text);
                        $my_text = str_replace('{username}', getRowRealtime('users', $row['user_id'], 'username'), $my_text);
                        $my_text = str_replace('{method}', 'Ví MOMO server1', $my_text);
                        $my_text = str_replace('{amount}', format_cash($amount), $my_text);
                        $my_text = str_replace('{price}', format_currency($amount), $my_text);
                        $my_text = str_replace('{time}', gettime(), $my_text);
                        sendMessAdmin($my_text);
                        echo '[<b style="color:green">-</b>] Xử lý thành công 1 hoá đơn.'.PHP_EOL;
                        break;
                    }
                }
            }
        }
    }
