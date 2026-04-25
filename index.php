<?php

class ProductCrawler
{
    private $pdo;
    private $baseUrl = "https://qvbadminton.com";

    public function __construct($pdo)
    {
        $this->pdo = $pdo;

        if (!file_exists("images")) {
            mkdir("images", 0777, true);
        }
    }

    public function crawl($url)
    {
        $html = $this->getHTML($url);
        if (!$html) {
            die("Không lấy được HTML");
        }

        [$title, $content] = $this->parseContent($html);

        // xử lý ảnh
        $result = $this->processImages($content);

        $content = $result['content'];
        $images  = $result['images'];

        // replace domain
        $content = str_replace("qvbadminton", "manhdungsports", $content);

        // remove address block
        $content = $this->removeAddressBlock($content);

        $content = $this->restructureContent($content);

        // 🔥 gắn lên đầu
        $content = $content;

        $this->saveToDB($title, $content, $url, $images);

        $this->render($content);
    }

    private function restructureContent($content)
    {
        $dom = new DOMDocument();

        @$dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $content,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $xpath = new DOMXPath($dom);

        // ===== 1. Rewrite H2 =====
        foreach ($xpath->query("//h2") as $h2) {

            $text = trim($h2->nodeValue);

            // bỏ số thứ tự
            $text = preg_replace('/^\d+\.\s*/', '', $text);

            // thay wording
            $text = str_replace(
                ["Giới thiệu", "Thông số kỹ thuật", "Công nghệ", "Đối tượng phù hợp"],
                ["Tổng quan", "Chi tiết kỹ thuật", "Công nghệ nổi bật", "Phù hợp với ai"],
                $text
            );

            $h2->nodeValue = $text;
        }

        // ===== 2. Rewrite Paragraph =====
        foreach ($xpath->query("//p") as $p) {

            $text = trim($p->nodeValue);

            if (!$text) continue;

            // thay từ đơn giản
            $text = str_replace(
                ["nổi bật", "mạnh mẽ", "thiết kế", "giúp", "tăng", "được"],
                ["đáng chú ý", "uy lực", "kiểu dáng", "hỗ trợ", "cải thiện", ""],
                $text
            );

            // tách câu dài
            $text = preg_replace('/,\s*/', '. ', $text);

            // random đảo câu nhẹ
            $sentences = preg_split('/\.\s+/', $text);

            if (count($sentences) > 2) {
                shuffle($sentences);
                $text = implode('. ', $sentences);
            }

            $p->nodeValue = trim($text, '. ') . '.';
        }

        // ===== 3. UL → P =====
        foreach ($xpath->query("//ul") as $ul) {

            $items = [];

            foreach ($xpath->query(".//li", $ul) as $li) {
                $items[] = trim($li->nodeValue);
            }

            if (!empty($items)) {

                // random join
                shuffle($items);

                $text = implode(", ", $items);

                $p = $dom->createElement("p", $text);

                $ul->parentNode->replaceChild($p, $ul);
            }
        }

        // ===== 4. XÓA LINK NGOÀI =====
        foreach ($xpath->query("//a") as $a) {

            $text = $a->nodeValue;
            $span = $dom->createElement("span", $text);

            $a->parentNode->replaceChild($span, $a);
        }

        // ===== CLEAN =====
        $html = $dom->saveHTML();
        return preg_replace('/<\?xml.*?\?>/', '', $html);
    }

    // ================= CORE =================

    private function getHTML($url)
    {
        return @file_get_contents($url);
    }

    private function parseContent($html)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Title
        $titleNode = $xpath->query("//h1")->item(0);
        $title = $titleNode ? trim($titleNode->nodeValue) : "No title";

        // 🔥 LẤY THEO ID tab-description
        $contentNode = $xpath->query("//div[@id='tab-description']")->item(0);

        // fallback nếu site đổi
        if (!$contentNode) {
            $contentNode = $xpath->query("//div[contains(@class,'tabbed-content')]")->item(0);
        }

        if (!$contentNode) {
            $contentNode = $xpath->query("//article")->item(0);
        }

        $content = $contentNode ? $dom->saveHTML($contentNode) : "";

        return [$title, $content];
    }

    private function processImages($content)
    {
        $dom = new DOMDocument();

        @$dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $content,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $xpath = new DOMXPath($dom);

        // ================= IMG =================
        $imgs = $xpath->query("//img");

        $imageList = [];

        foreach ($imgs as $img) {

            $src = $img->getAttribute("src");

            // ❌ không có src → xoá
            if (!$src) {
                $img->parentNode->removeChild($img);
                continue;
            }

            // fix link tương đối
            if (!str_starts_with($src, "http")) {
                $src = $this->baseUrl . $src;
            }

            $localPath = $this->downloadImage($src);

            // ❌ download fail → xoá img
            if (!$localPath) {
                $img->parentNode->removeChild($img);
                continue;
            }

            try {
                $this->processImage($localPath);

                // replace src
                $img->setAttribute("src", $localPath);

                // 🔥 QUAN TRỌNG: xoá responsive WP
                $img->removeAttribute("srcset");
                $img->removeAttribute("sizes");

                // 🔥 clean attribute rác
                $img->removeAttribute("class");
                $img->removeAttribute("decoding");
                $img->removeAttribute("loading");

                $imageList[] = $localPath;

            } catch (Exception $e) {
                $img->parentNode->removeChild($img);
            }
        }

        // ================= FIGURE =================
        // unwrap <figure> (giữ lại img + caption)
        $figures = $xpath->query("//figure");

        foreach ($figures as $figure) {

            $parent = $figure->parentNode;

            if (!$parent) continue;

            while ($figure->firstChild) {
                $parent->insertBefore($figure->firstChild, $figure);
            }

            $parent->removeChild($figure);
        }

        // ================= CLEAN =================
        $contentClean = $dom->saveHTML();

        // remove xml header
        $contentClean = preg_replace('/<\?xml.*?\?>/', '', $contentClean);

        return [
            'content' => $contentClean,
            'images'  => $imageList
        ];
    }

    private function removeAddressBlock($content)
    {
        $dom = new DOMDocument();

        @$dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $content,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        $xpath = new DOMXPath($dom);

        // 🔥 full keyword
        $nodes = $xpath->query("
            //*[
                contains(text(),'CS1') or
                contains(text(),'CS2') or
                contains(text(),'CS3') or
                contains(text(),'Quốc Việt') or
                contains(text(),'Quoc Viet') or
                contains(text(),'Qvbadminton.com') or
                contains(text(),'QUỐC VIỆT BADMINTON')
            ]
        ");

        foreach ($nodes as $node) {
            $parent = $node->parentNode;

            if ($parent && $parent->parentNode) {
                $parent->parentNode->removeChild($parent);
            }
        }

        return preg_replace('/<\?xml.*?\?>/', '', $dom->saveHTML());
    }

    private function downloadImage($url)
    {
        $data = @file_get_contents($url);
        if (!$data) return null;

        $file = "images/" . md5($url) . ".jpg";
        file_put_contents($file, $data);

        return $file;
    }

    private function processImage($file)
    {
        try {
            $image = new Imagick($file);

            $white = new Imagick();
            $white->newImage(170, 80, new ImagickPixel('white'));

            $image->compositeImage($white, Imagick::COMPOSITE_OVER, 0, 0);
            $image->writeImage($file);

            $white->clear();
            $image->clear();

        } catch (Exception $e) {
            echo "Lỗi ảnh: " . $e->getMessage() . "<br>";
        }
    }

    private function saveToDB($title, $content, $url, $images)
    {
        $slug = $this->createSlug($title);

        $thumbnail = !empty($images) ? $images[0] : null;

        $stmt = $this->pdo->prepare("
            INSERT INTO products 
            (title, slug, content, thumbnail, images, source_url, status)
            VALUES 
            (:title, :slug, :content, :thumbnail, :images, :url, 0)
        ");

        try {
            $stmt->execute([
                ':title'     => $title,
                ':slug'      => $slug,
                ':content'   => $content,
                ':thumbnail' => $thumbnail,
                ':images'    => json_encode($images, JSON_UNESCAPED_UNICODE),
                ':url'       => $url
            ]);

            echo "✅ Insert OK<br>";

        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                echo "❌ Duplicate<br>";
            } else {
                echo $e->getMessage();
            }
        }
    }

    private function createSlug($text)
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        $text = preg_replace('/[^a-zA-Z0-9-]/', '-', $text);
        $text = strtolower($text);
        return trim(preg_replace('/-+/', '-', $text), '-');
    }

    private function render($content)
    {
        echo "<div style='max-width:800px;margin:auto;line-height:1.6'>";
        echo $content;
        echo "</div>";
    }
}


// ================= RUN =================

$pdo = new PDO(
    "mysql:host=db;dbname=manhdungcrawl;charset=utf8mb4",
    "root",
    "root"
);

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$crawler = new ProductCrawler($pdo);

$crawler->crawl("https://qvbadminton.com/vot-cau-long-yonex-astrox-99-game-2025/");