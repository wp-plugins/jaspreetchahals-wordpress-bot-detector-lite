<?php

/*
    Plugin Name: JaspreetChahal's wordpress bot detector lite
    Plugin URI: http://jaspreetchahal.org/wordpress-bot-detection-plugin-lite
    Description: This plugin does what the title says. It identifies Bots and send you an email about the bot activity. 
    Author: Jaspreet Chahal
    Version: 1.0
    Author URI: http://jaspreetchahal.org
    License: GPLv2 or later
    */

    /*
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
    */
    // if not an admin just block access
    if(preg_match('/admin\.php/',$_SERVER['REQUEST_URI']) && is_admin() == false) {
        return false;
    }
    
    global $wpdb;
    define('JCORGBOTDETECTOR_VERION','1.0');
    define('JCORGBOTDETECTOR_DB_VERION','1.0');
    define('JCORGBOTDETECTOR_ISLITE',false);
    define('JCORGBOTDETECTOR_TABLE',$wpdb->prefix."jcorgbotinfo");
    define('JCORGBOTDETECTOR_HISTORYTABLE',$wpdb->prefix."jcorgbothistory");

    require dirname(__FILE__)."/util.php";
    
    load_plugin_textdomain("jcorgbot",false,basename( dirname( __FILE__ ) ) . '/languages');
        
    
    register_activation_hook(__FILE__,'jcorgbotdetector_activate');
    function jcorgbotdetector_activate() {
        global $wpdb;
        if(get_option('jcorgbotdetector_dbversion') == "" || get_option('jcorgbotdetector_dbversion') < JCORGBOTDETECTOR_DB_VERION)  {
            $run_inserts = false;
            if(get_option('jcorgbotdetector_dbversion') == "") {
                $run_inserts = true;
            }
            
            add_option('jcorgbotdetector_db_version',JCORGBOTDETECTOR_DB_VERION);
            add_option('jcorgbotdetector_last_email_sent',0);
            add_option('jcorgbotdetector_email_interval',24);
            add_option('jcorgbotdetector_email_format','html');
            add_option('jcorgbotdetector_history_to_keep',30);
            add_option('jcorgbotdetector_email','');
            
            jcorgbotdetector_setupdb();
            
            if($run_inserts) {
                $inserts_query = "insert into ".JCORGBOTDETECTOR_TABLE." (`id`,`identify`,`name`,`url`,`created_on`,`active`,`ctype`) values (1,'AdsBot-Google','AdsBot','',1338459875,'Yes','Bot'),(2,'ia_archiver','Alexa','',1338459875,'Yes','Bot'),(3,'Scooter','Alta Vista','',1338459875,'Yes','Bot'),(15,'Googlebot','Google','',1338459875,'Yes','Bot'),(52,'YahooSeeker/','YahooSeeker','',1338459875,'Yes','Bot');";
                $wpdb->query($inserts_query);
            }
        }
    }

    register_deactivation_hook(__FILE__,'jcorgbotdetector_deactivate');
    function jcorgbotdetector_deactivate() {
        
    }

    add_action("admin_menu","jcorgbotdetector_menu");
    function jcorgbotdetector_menu() {
        add_menu_page(__('JC Bot Detector','jcorgbot'),__('JC Bot Detector','jcorgbot'),'manage_options','jcorgbotdetect','jcorgbotdetector_settings',plugins_url("bot.png",__FILE__));
        add_submenu_page('jcorgbotdetect',__('Manage Bots','jcorgbot'),__('Manage Bots','jcorgbot'),'manage_options','jcorgbotdetect-sub','jcorgbotdetector_managebots');
        add_submenu_page('jcorgbotdetect',__('Add/Edit Bot','jcorgbot'),__('Add/Edit Bot','jcorgbot'),'manage_options','jcorgbotdetect-add','jcorgbotdetector_addbot');
        add_submenu_page('jcorgbotdetect',__('History Report','jcorgbot'),__('History Report','jcorgbot'),'manage_options','jcorgbotdetect-rep','jcorgbotdetector_reports');
        add_submenu_page('jcorgbotdetect',__('Live View','jcorgbot'),__('Live View','jcorgbot'),'manage_options','jcorgbotdetect-live','jcorgbotdetector_liveview');        
    }
    add_action('admin_init','jcorgbotinfo_regsettings');
    function jcorgbotinfo_regsettings() {
        
        register_setting("jcorgbotinfo-setting","jcorgbotdetector_email_interval");
        register_setting("jcorgbotinfo-setting","jcorgbotdetector_email_format");
        register_setting("jcorgbotinfo-setting","jcorgbotdetector_history_to_keep");
        register_setting("jcorgbotinfo-setting","jcorgbotdetector_email","jcorgbotinfo_validate_options");     
        wp_enqueue_script('jquery');
        wp_enqueue_style('jcorrbotdetect',plugins_url("jcorgbotdetect.css",__FILE__));
    }
    function jcorgbotinfo_validate_options($input) {
        if(is_email($input) == false) { 
            add_settings_error("jcorgbotdetector_email","112212",__("Invalid Email ID"),'error');
        }
        return $input;
    }    
    
    add_action('init','jcorgbotdetector_init');
    function jcorgbotdetector_init() {
        global $wpdb;
        $query = "select 
                        * 
                        from 
                        ".JCORGBOTDETECTOR_TABLE;
        // delete stuff older than N configured days
        $wpdb->query("delete from ".JCORGBOTDETECTOR_HISTORYTABLE." where created_on <".(time() - (intval(get_option("jcorgbotdetector_history_to_keep")) *86400)));
        
        $bots  = $wpdb->get_results($query);
        foreach ($bots as $bot) {
            if(strpos(strtolower($_SERVER["HTTP_USER_AGENT"]),strtolower($bot->name)) !== false && $bot->active == "Yes") {                
                $wpdb->insert(JCORGBOTDETECTOR_HISTORYTABLE,array("bot_id"=>$bot->id,
                                                                    "identify"=>$bot->identify,
                                                                    "created_on"=>time(),
                                                                    "page_url"=>JCORGbotinfoutil::jcorgGetURL()
                                                                    ));
            }
        }
        
        // check time and send email
        $jcorgbotinterval = intval(get_option("jcorgbotdetector_email_interval"))*3600;
        
        if((time() - get_option("jcorgbotdetector_last_email_sent")) > $jcorgbotinterval && strlen(get_option("jcorgbotdetector_email"))>0) {
            $to = get_option("jcorgbotdetector_email");
            $sub = "JC Bot Detector Results";
            if(get_option("jcorgbotdetector_email_format") == "html") {
                $msg = "<div style='font-size:12px;font-family:arial'><strong>".__("Dear admin","jcorgbot").",</strong><br><br>
                ".__("Below are the results of Bot activity on your site","jcorgbot")."".jcorgBIHTMLEmail(intval(get_option("jcorgbotdetector_last_email_sent")))."</div><br><br>Kind Regards, <br>JC Bot Detector";
                $headers = array("From: JC Bot Detector <".get_bloginfo("admin_email").">","Content-Type: text/html");
                $h = implode("\r\n",$headers) . "\r\n";
                wp_mail($to, $sub, $msg, $h);
            }
            else {
                $msg = __("Dear admin","jcorgbot")."\n\n".__("Below are the results of Bot activity on your site","jcorgbot")."\n\n".jcorgBITextEmail(intval(get_option("jcorgbotdetector_last_email_sent")))."\nKind Regards, \nJC Bot Detector";
                $headers = array("From: JC Bot Detector <".get_bloginfo("admin_email").">");
                $h = implode("\r\n",$headers) . "\r\n";
                wp_mail($to, $sub, $msg, $h);
            }
            update_option("jcorgbotdetector_last_email_sent",time());
        }
    }

    function jcorgbotdetector_setupdb() {
        global $wpdb;
        
        $query = ' CREATE TABLE '.JCORGBOTDETECTOR_TABLE.' (
                  `id` INT (3) UNSIGNED NOT NULL AUTO_INCREMENT,
                  `identify` CHAR(40) NOT NULL,
                  `name` CHAR(60) NOT NULL,
                  `url` CHAR(80),
                  `created_on` INT (11) NOT NULL DEFAULT 0,
                  `active` ENUM ("Yes", "No") NOT NULL DEFAULT "Yes",
                  `ctype` ENUM ("Bot","Spider","Crawler","Validator","Picsearch","Linkcheck","Sitesearch") NOT NULL DEFAULT "Bot",
                   PRIMARY KEY (`id`)
                   ) ;';
        $wpdb->query($query);
        
        $query = 'CREATE TABLE '.JCORGBOTDETECTOR_HISTORYTABLE.' (
                  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                  `bot_id` int(3) DEFAULT 0,
                  `identify` char(40) NOT NULL,
                  `created_on` int(11) NOT NULL,
                  `page_url` char(255) NOT NULL,
                  PRIMARY KEY (`id`)
                ) DEFAULT CHARSET=utf8';
        $wpdb->query($query);
        
        $query = "ALTER TABLE ".JCORGBOTDETECTOR_HISTORYTABLE." ADD INDEX `co` (`bot_id`, `created_on`); ";
    }

    function jcorgbotdetector_settings() {
        JCORGbotinfoutil::donationDetail();
        
        ?>    
        <div class="wrap" >
            <?php             
            
            screen_icon('tools');?>
            <h2><?php _e('JaspreetChahal\'s Bot detector settings','jcorgbot')?></h2>
            <?php 
                $errors = get_settings_errors("",true);
                $errmsgs = array();
                $msgs = "";
                if(count($errors) >0)
                foreach ($errors as $error) {
                    if($error["type"] == "error")
                        $errmsgs[] = $error["message"];
                    else if($error["type"] == "updated")
                        $msgs = $error["message"];
                }

                echo JCORGbotinfoutil::makeErrorsHtml($errmsgs,'warning1');
                if(strlen($msgs) > 0) {
                    echo "<div class='jcorgbsuccess' style='width:90%'>$msgs</div>";
                }
            
            ?><br><br>
            <form action="options.php" method="post" id="jcorgbotinfo_settings_form">
            <?php settings_fields("jcorgbotinfo-setting");?>
            <table class="widefat" style="width: 500px;" cellpadding="7">
                <tr valign="top">
                    <th scope="row"><?php _e("Notification email","jcorgbot") ?></th>
                    <td><input type="text" name="jcorgbotdetector_email"
                            value="<?php echo get_option('jcorgbotdetector_email'); ?>"  style="padding:5px" size="40"/></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e("Email Interval","jcorgbot") ?></th>
                    <td><input type="number" name="jcorgbotdetector_email_interval" style="width:60px;" maxlength="4"
                            value="<?php echo get_option("jcorgbotdetector_email_interval")?get_option("jcorgbotdetector_email_interval"):"24"; ?>" style="padding:5px"/> <?php _e("hours","jcorgbot") ?></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e("Keep history for","jcorgbot") ?></th>
                    <td><input type="number" name="jcorgbotdetector_history_to_keep" min="0"size="2" maxlength="2" style="width: 40px;padding:5px"
                        value="<?php echo get_option('jcorgbotdetector_history_to_keep')?get_option('jcorgbotdetector_history_to_keep'):30; ?>" /> <?php _e("days","jcorgbot") ?></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php _e("Email format","jcorgbot") ?></th>
                    <td><input type="radio" name="jcorgbotdetector_email_format" <?php if(get_option('jcorgbotdetector_email_format') == "html" || get_option('jcorgbotdetector_email_format') == "") echo "checked='checked'";?>
                            value="html" 
                            /> Html 
                            <input type="radio" name="jcorgbotdetector_email_format" <?php if(get_option('jcorgbotdetector_email_format') == "text") echo "checked='checked'";?>
                            value="text" 
                            /> Text 
                    </td>
                </tr>           
        </table>
        <p class="submit">
            <input type="submit" class="button-primary"
                value="Save Changes" />
        </p>          
            </form>
        </div>
        
        <?php
        echo JCORGbotinfoutil::jcorgfeeds();
    }
    function jcorgbotdetector_managebots() {
        
        ?> 
        <div id="icon-settings" class="icon-settings icon32"></div><h1>Currently configured bots</h1>
        <h2>Available in Pro Version</h2>
        
        
        <?php
        
    }    
    function jcorgbotdetector_addbot() {
        ?> 
        <div id="icon-settings" class="icon-appearance icon32 "></div><h1><?php $label = __("Add","jcorgbot"); if($_GET["action"] == "modify") {$label= __("Modify","jcorgbot");} echo $label; _e(" bot",'jcorgbot')?></h1>
        <h2>Available in Pro Version</h2>
        
        <?php        
    }    
    
    function jcorgbotdetector_reports() {
        ?>    
        <div style="padding-top:0px" class="icon32"><img src="<?php echo plugins_url("bot.png",__FILE__)?>" height="32" width="32"></div><h1><?php _e("Bot activity report","jcorgbot")?></h1>
        <h2>Available in Pro Version</h2>
     <?php       
        
    }    
    
    function jcorgbotdetector_liveview() {
        
        
     ?> 
     
     <div style="padding-top:0px" class="icon32"><img src="<?php echo plugins_url("bot.png",__FILE__)?>" height="32" width="32"></div><h1><?php _e("Bots currently crawling your web pages","jcorgbot")?></h1>
     <div id="jcorgbotinfoupdating" style="margin-top:25px;height:20px;font-size:14px"><?php _e("Querying, Please wait.....","jcorgbot"); ?></div>
     <div id="jcorgbotinfoupdatingdata" style="margin-top:25px;font-size:18px">
        Available in Pro Version
     </div>
     <?php
        
    }
    
    
    function jcorgBIHTMLEmail($since) {
        global $wpdb;
        
        $email_table="";
            $query = "SELECT 
                          *,".JCORGBOTDETECTOR_HISTORYTABLE.".created_on as co
                        FROM
                          ".JCORGBOTDETECTOR_HISTORYTABLE." ,".JCORGBOTDETECTOR_TABLE."
                        WHERE 
                        ".JCORGBOTDETECTOR_TABLE.".`id` = ".JCORGBOTDETECTOR_HISTORYTABLE.".`bot_id` 
                        AND ".JCORGBOTDETECTOR_HISTORYTABLE.".created_on > $since";
            $rows = $wpdb->get_results($query);
            $email_table = '
             
            <table width="100%" border="1" cellspacing="5" cellpadding="5" class="widefat">
              <tr>
                <th width="25%" scope="col">'.__("Bot name","jcorgbot").'</th>
                <th width="25%" scope="col">'.__("Url crawled","jcorgbot").'</th>
                <th width="25%" scope="col">'.__("Visit time","jcorgbot").'</th>
                <th width="25%" scope="col">'.__("Bot type","jcorgbot").'</th>
              </tr>
            ';  
            if(count($row) >0){
                foreach ($rows as $row) {
                 $email_table .= "  
                  <tr>
                    <td>$row->name</td>
                    <td>$row->page_url</td>
                    <td>".date("d/m/Y h:i:s A",$row->co)."</td>
                    <td>$row->ctype</td>
                  </tr>";                        
                }  
            }
            else {
                $email_table .= " <tr><td colspan='4' style='padding:5px; color:#c00'>".__("No bots detected on your site","jcorgbot")."</td></tr>";
            }
            return $email_table .= " </table>";                    
    }        

    function jcorgBITextEmail($since) {
        global $wpdb;
        
        $email_text="";
            $query = "SELECT 
                          *,".JCORGBOTDETECTOR_HISTORYTABLE.".created_on as co
                        FROM
                          ".JCORGBOTDETECTOR_HISTORYTABLE." ,".JCORGBOTDETECTOR_TABLE."
                        WHERE 
                        ".JCORGBOTDETECTOR_TABLE.".`id` = ".JCORGBOTDETECTOR_HISTORYTABLE.".`bot_id` 
                        AND ".JCORGBOTDETECTOR_HISTORYTABLE.".created_on > $since";
            $rows = $wpdb->get_results($query);
            $email_text = '
==========================================
'.__("Results","jcorgbot").'
==========================================

';  
            if(count($row) >0){
                foreach ($rows as $row) {
                 $email_text .=  
__("Bot name","jcorgbot").": $row->name
".__("Url crawled","jcorgbot").": $row->page_url
".__("Visit time","jcorgbot").": ".date("d/m/Y h:i:s A",$row->co)."
".__("Bot type","jcorgbot").": $row->ctype

-----------------------------

";                        
                }  
            }
            else {
                $email_text .= __("No bots detected on your site","jcorgbot");
            }
            return $email_text;                    
    }        

