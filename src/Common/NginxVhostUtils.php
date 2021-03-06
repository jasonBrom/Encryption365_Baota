<?php
namespace TrustOcean\Encryption365\Common;

use TrustOcean\Encryption365\Encryption365Exception;

class NginxVhostUtils {

    /**
     * 系统默认的配置文件路径, 这里默认选择Nginx目录
     * @return string
     */
    public static function getConfigPath()
    {
        // 检查Webserver类型，生成 configPath
        $db = DatabaseUtils::initBaoTaSystemDatabase();
        $ch = $db->query("select `webserver` from config where id =1")->fetch();
        $webserver = $ch['webserver'];
        return "/www/server/panel/vhost/$webserver/";
    }

    /**
     * 获取 WebServer 类型
     * @return mixed
     */
    public static function getWebserver()
    {
        $db = DatabaseUtils::initBaoTaSystemDatabase();
        $ch = $db->query("select `webserver` from config where id =1")->fetch();
        return $ch['webserver'];
    }

    /**
     * 通过配置文件查找网站运行目录
     * @param $configFile
     * @return string
     * @throws Encryption365Exception
     */
    public static function getVhostRunPath($configFile) {
        $lines = file($configFile);
        foreach ($lines as $key => $line) {
            # 检查获取配置文件中实际的 RUN PATH，访问路径
            $webserverType = self::getWebserver();
            switch ($webserverType) {
                case 'nginx':
                    $webServerPathKey = 'root';
                    break;
                case 'apache':
                    $webServerPathKey = 'DocumentRoot';
                    break;
                case 'openlitespeed':
                    $webServerPathKey = 'vhRoot';
                    break;
                default:
                    $webServerPathKey = 'root';
            }
            if (strpos($line, $webServerPathKey) !== FALSE) {
                if ($webserverType === "nginx") {
                    preg_match("/\ \/(.+);/i", $line, $matches);
                    $runPath = trim("/" . end($matches));
                } elseif ($webserverType === "apache") {
                    preg_match("/DocumentRoot\ \"(.+)\"/i", $line, $matches);
                    $runPath = trim(end($matches));
                } elseif ($webserverType === "openlitespeed") {
                    preg_match("/vhRoot\ (.*)/i", $line, $matches);
                    $runPath = trim(end($matches));
                } else {
                    throw new Encryption365Exception("获取站点信息失败, Web服务器配置异常");
                }
            }
        }
        return $runPath;
    }
    /**
     * 查询本地站点的证书信息
     * @param $siteName
     * @return array
     * @throws Encryption365Exception
     */
    public static function getVhostConfigInfo($siteName) {
        $configs = self::getConfigPath()."$siteName.conf";
        $config_file = $configs;
        $has_cert = false;
        $cert_file = "";
        $key_file = "";
        $ssl_enabled = false;
        $cert_info = [];
        $is_redirect_https = false;
        $cert_class = "DV";
        $runPath = self::getVhostRunPath($configs);

        $lines = file($configs);
        foreach ($lines as $key => $line) {
            # 检查是否配置了SSL证书
            if($ssl = strpos($line, "listen 443") !== false){
                if(strpos($ecn = substr($line, 0, $ssl), "#") === false){
                    $ssl_enabled = TRUE;
                }
            }
            # 检查证书文件
            if($ssl = strpos($line, "ssl_certificate ") !== false){
                if(strpos(substr($line, 0, $ssl), "#") === false){
                    # 存在证书文件
                    $has_cert = true;
                    $ep = strpos($line, ";");
                    $dk = strpos($line, "ssl_certificate");
                    $ed = substr($line, $dk, $ep);
                    $es = substr($ed, 0, -1);
                    $e = explode(" ", $es);
                    $cert_file = trim(str_replace(";", "",
                        $s = str_replace(PHP_EOL, "", end($e))));
                    $cert_info = openssl_x509_parse(((explode("-----END CERTIFICATE-----", file_get_contents($cert_file))[0])."-----END CERTIFICATE-----"), TRUE);
                    $cert_info['valid_from'] = date('Y-m-d H:i:s', $cert_info['validFrom_time_t']);
                    $cert_info['valid_to'] = date('Y-m-d H:i:s', $cert_info['validTo_time_t']);
                    $cert_info['valid_to_date'] = date('Y-m-d', $cert_info['validTo_time_t']);
                    // 判断证书类型
                    if(isset($cert_info['subject']['O'])){
                        $cert_class = "OV";
                    }
                    if(isset($cert_info['subject']['serialNumber']) && isset($cert_info['subject']['businessCategory'])){
                        $cert_class = "EV";
                    }
                    $cert_info['cert_class'] = $cert_class;
                }
            }
            # 私钥
            if($ssl = strpos($line, "ssl_certificate_key") !== false){
                if(strpos(substr($line, 0, $ssl), "#") === false){
                    # 存在证书文件
                    $smk = strpos($line, ";");
                    $ek2 = strpos($line, "ssl_certificate_key");
                    $sks = substr($line, $ek2, $smk);
                    $sw = substr($sks, 0, -1);
                    $sx = explode(" ", $sw);
                    $sc = end($sx);
                    $sa = str_replace(PHP_EOL, "", $sc);
                    $key_file = trim(str_replace(";", "", $sa));
                }
            }
            # 是否开启了强制跳转
            if($ssl = strpos($line, '$server_port !~ 443') !== false){
                if(strpos($eols = substr($line, 0, $ssl), "#") === false){
                    $is_redirect_https = TRUE;
                }
            }
        }

        return array(
            "config_file"=>$config_file,
            "run_path"=>$runPath,
            "has_cert"=>$has_cert,
            "cert_file"=>$cert_file,
            "key_file"=>$key_file,
            "ssl_enabled"=>$ssl_enabled,
            "cert_info"=>$cert_info,
            "is_redirect_https"=>$is_redirect_https
        );
    }

    /**
     * 根据路径创建验证文件
     * @param $sitePath
     * @param $validationPath
     * @param $fileName
     * @param $content
     * @return bool
     */
    public static function writeValidationFile($sitePath, $validationPath, $fileName, $content) {
        if(is_dir($sitePath)==false){return false;}
        $fullValidationPath = $sitePath.'/'.$validationPath;
        // 检查并创建验证目录
        if(is_dir(realpath($fullValidationPath)) === FALSE){
            mkdir($sitePath.'/'.$validationPath, 0755, TRUE);
        }
        // 创建验证文件
        if(is_file($fullValidationPath."/$fileName")===true){
            unlink($fullValidationPath."/$fileName");
        }
        file_put_contents($fullValidationPath."/$fileName", $content);
    }
}