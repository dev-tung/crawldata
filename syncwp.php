<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);


// ================= DB =================
$pdo = new PDO(
    "mysql:host=db;dbname=manhdungcrawl;charset=utf8mb4",
    "root",
    "root",
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]
);

// 👇 GỌI Ở ĐÂY (NGAY SAU PDO)
ensureColumnExists($pdo, 'products', 'wp_product_id', 'INT NULL');
ensureColumnExists($pdo, 'products', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');


// ================= WOOCOMMERCE PUBLISHER =================
class WooPublisher
{
    private $wpApi;
    private $ck;
    private $cs;
    private $wpUser;
    private $wpPass;

    public function __construct($wpApi, $ck, $cs, $wpUser, $wpPass)
    {
        $this->wpApi = rtrim($wpApi, '/');
        $this->ck = $ck;
        $this->cs = $cs;
        $this->wpUser = $wpUser;
        $this->wpPass = $wpPass;
    }

    // ================= MAIN =================
    public function publishProduct($post)
    {
        echo "➡️ Processing: {$post['title']}<br>";

        $content = $post['content'];
        $images  = json_decode($post['images'], true) ?? [];

        $imageUrls = [];
        $imageMap  = [];

        // 🔥 upload + map ảnh
        foreach ($images as $img) {

            if (!file_exists($img)) {
                echo "❌ Missing: $img<br>";
                continue;
            }

            $url = $this->uploadImage($img);

            if ($url) {
                $imageUrls[] = ["src" => $url];

                // 🔥 map local → wp
                $imageMap[$img] = $url;

                echo "✅ IMG: $url<br>";
            }

            // 🔥 chống spam server
            usleep(500000);
        }

        // 🔥 replace ảnh trong content
        foreach ($imageMap as $local => $wpUrl) {
            $content = str_replace($local, $wpUrl, $content);
        }

        // 🔥 fallback nếu còn sót path "images/..."
        $content = preg_replace(
            '#src=["\']?images/(.*?)["\']?#',
            'src="https://manhdungsports.com/wp-content/uploads/$1"',
            $content
        );

        // ================= BUILD PRODUCT =================
        $data = [
            "name" => $post['title'],
            "type" => "simple",
            "status" => "draft",

            "description" => $content,
            "short_description" => mb_substr(strip_tags($content), 0, 200),

            "regular_price" => (string)$this->generatePrice(),

            "images" => $imageUrls,
            "slug" => $post['slug']
        ];

        return $this->createProduct($data);
    }
    // ================= IMAGE =================
    private function prepareImages($images)
    {
        $result = [];

        foreach ($images as $img) {

            if (!file_exists($img)) {
                echo "❌ Missing file: $img<br>";
                continue;
            }

            $url = $this->uploadImage($img);

            if ($url) {
                $result[] = ["src" => $url];
                echo "✅ IMG: $url<br>";
            }
        }

        return $result;
    }

    private function uploadImage($filePath)
    {
        if (!file_exists($filePath)) return null;

        $hash = md5_file($filePath);

        $ch = curl_init($this->wpApi . "/wp/v2/media");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $this->wpUser . ":" . $this->wpPass,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,

            CURLOPT_HTTPHEADER => [
                "Content-Disposition: attachment; filename=" . basename($filePath),
                "Content-Type: image/jpeg",
                "Expect:",
                "X-Image-Hash: $hash" // 👈 custom header
            ],

            CURLOPT_POSTFIELDS => file_get_contents($filePath)
        ]);

        $res = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($http == 201) {
            $json = json_decode($res, true);
            return $json['source_url'] ?? null;
        }

        return null;
    }

    
    // ================= PRODUCT =================
    private function createProduct($data)
    {
        $ch = curl_init($this->wpApi . "/wc/v3/products");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $this->ck . ":" . $this->cs,

            // 🔥 FIX SSL + HTTP2
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,

            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Expect:" // 👈 QUAN TRỌNG
            ],

            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,

            CURLOPT_POSTFIELDS => json_encode($data)
        ]);

        $res = curl_exec($ch);

        $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        // 🔥 LOG
        echo "PRODUCT HTTP: $http<br>";

        // 🔥 SUCCESS (quan trọng nhất)
        if ($http == 201) {
            echo "✅ PRODUCT CREATED<br>";
            $json = json_decode($res, true);
            return $json;
        }

        // ❌ FAIL thật
        echo "❌ PRODUCT FAIL ($http)<br>";
        if ($error) echo "cURL: $error<br>";
        echo "<pre>$res</pre>";

        return null;
    }

    // ================= PRICE =================
    private function generatePrice()
    {
        return rand(1000000, 3000000);
    }
}


// ================= WORKER =================
class Worker
{
    private $pdo;
    private $publisher;

    public function __construct($pdo, $publisher)
    {
        $this->pdo = $pdo;
        $this->publisher = $publisher;
    }

    public function run($limit = 5)
    {
        echo "🚀 START<br>";

        $stmt = $this->pdo->prepare("
            SELECT * FROM products 
            WHERE status = 0 
            LIMIT :limit
        ");

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$posts) {
            echo "❌ No data<br>";
            return;
        }

        foreach ($posts as $post) {

            $res = $this->publisher->publishProduct($post);

            if (!empty($res['id'])) {

                $update = $this->pdo->prepare("
                    UPDATE products 
                    SET status = 1, wp_product_id = :wpid
                    WHERE id = :id
                ");

                $update->execute([
                    ':id' => $post['id'],
                    ':wpid' => $res['id']
                ]);

                echo "✅ SUCCESS - ID: {$res['id']}<br>";

            } else {

                echo "❌ FAIL<br>";
                echo "<pre>";
                print_r($res);
                echo "</pre>";
            }

            usleep(500000);
        }
    }
}

// ================= AUTO MIGRATION =================
function ensureColumnExists($pdo, $table, $column, $definition)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = :table 
        AND COLUMN_NAME = :column
    ");

    $stmt->execute([
        ':table' => $table,
        ':column' => $column
    ]);

    $exists = $stmt->fetchColumn();

    if (!$exists) {
        echo "⚙️ Adding column: $column<br>";

        $pdo->exec("
            ALTER TABLE `$table` 
            ADD COLUMN `$column` $definition
        ");
    }
}

// ================= RUN =================

$publisher = new WooPublisher(
    "https://manhdungsports.com/wp-json",

    "ck_840f3c0b7fe35728f83a4ba8a5a08c845577e138",
    "cs_6d9d4dee6511a87969f53538cc3c1aad065615b0",

    "admin",
    "u2Bu 2eAk 7Pt1 7Evk yNnU 8Tmz" // 🔥 Application Password (KHÔNG phải password login)
);

$worker = new Worker($pdo, $publisher);
$worker->run(5);