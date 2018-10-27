<?php
declare(strict_types=1);

if(!isset($argv[1])){
    echo "Please set memberName\n";
    echo "php {$argv[0]} MEMBER_NAME\n";
    die();
}

date_default_timezone_set('Asia/Tokyo');

$memberName = $argv[1];
$url = "https://blog-api.line-apps.com/v1/blog/${memberName}/articles?withBlog=1&pageKey=";

define('MEMBER_DIR_ABS', __DIR__ . "/${memberName}");
define('MEMBER_DIR_REL', "./${memberName}");

$db = new SQLite3(__DIR__ . "/${memberName}.db");
$db->exec("CREATE TABLE IF NOT EXISTS ${memberName} (id INTEGER UNIQUE, title TEXT, createdAt INTEGER, plain TEXT, content TEXT)");

$lastId = $db->querySingle("SELECT max(id) FROM ${memberName}");
$lastId = ($lastId === NULL) ? 0 : $lastId;

$articles = array();

echo '0 posts done';
for($i = 1; ; $i++){
    $str = safeFileGet($url . $i);
    $json = json_decode($str, true);

    if(!isRequestSuccess($json)){
        echo "Error. Server responsed not 200.\npageKey is ${i}\nExit...";
        die();
    }

    $breakFlag = false;

    for($j = 0; $j < count($json['data']['rows']); $j++){
        $current = $json['data']['rows'][$j];

        $id = $current['id'];
        $title = trim($current['title']);
        $createdAt = $current['createdAt'];
        $plain = trim($current['bodyPlain']);
        $content = trim(getArticleContent($current['body'], $createdAt));

        if($id <= $lastId){
            $breakFlag = true;
            break;
        }

        array_push($articles, array(
            'id' => $id,
            'title' => $title,
            'createdAt' => $createdAt,
            'plain' => $plain,
            'content' => $content)
        );

        echo "\r";
        echo count($articles) . ' posts done';
    }

    if(!isNextPageExists($json)){
        break;
    }

    if($breakFlag){
        break;
    }
}

$articles = array_reverse($articles);
foreach($articles as $article){
    $stmt = $db->prepare("INSERT INTO ${memberName} VALUES (:id, :title, :createdAt, :plain, :content)");
    $stmt->bindValue(':id', $article['id'], SQLITE3_INTEGER);
    $stmt->bindValue(':title', $article['title'], SQLITE3_TEXT);
    $stmt->bindValue(':createdAt', $article['createdAt'], SQLITE3_INTEGER);
    $stmt->bindValue(':plain', $article['plain'], SQLITE3_TEXT);
    $stmt->bindValue(':content', $article['content'], SQLITE3_TEXT);
    $stmt->execute();
}
echo "\nFinished!\n";



function isRequestSuccess(array $json): bool{
    return $json['status'] === 200;
}

function isNextPageExists(array $json): bool{
    return isset($json['data']['nextPageKey']);
}


function getArticleContent(string $body, int $createdAt): string{
    if(!file_exists(MEMBER_DIR_ABS)){
        mkdir(MEMBER_DIR_ABS);
    }

    $date = date('Y-m-d H.i.s', $createdAt);
    $content = replaceMediaUrl($body, $date);
    return $content;
}

function replaceMediaUrl(string $content, string $date): string{
    $mediaCount = 1;
    $instagramImgPattern = '|(<div\s+?class="embed-instagram-media">)<a\s+?href="(https://www\.instagram\.com/p/.+?/)"(.*?)><img\s+?src="https://scontent\.cdninstagram\.com/.+?"(.*?)/></a></div>|s';
    if(preg_match_all($instagramImgPattern, $content, $m) > 0){
        for($i = 0; $i < count($m[0]); $i++){
            $mediaPath = saveMedia("{$m[2][$i]}media/?size=l", $date, $mediaCount++);

            $content = preg_replace($instagramImgPattern, "$1<a href=\"${mediaPath}\"$3><img src=\"${mediaPath}\"$4/></a></div>", $content, 1);
        }
    }

    $instagramVideoPattern = '|<video\s+?controls(.*?)poster="(https://scontent\.cdninstagram\.com/.+?)"(.*?)><source\s+?src="(https://scontent\.cdninstagram\.com/.+?)"></source><a.+?href=.+?><img.+?src=".+?"(.*?)/></a></video>|s';
    if(preg_match_all($instagramVideoPattern, $content, $m) > 0){
        for($i = 0; $i < count($m[0]); $i++){
            $posterImgPath = saveMedia($m[2][$i], $date, $mediaCount++);
            $videoPath = saveMedia($m[4][$i], $date, $mediaCount++);

            $content = preg_replace($instagramVideoPattern, "<video controls poster=\"${posterImgPath}\"$1$3><source src=\"${videoPath}\"></source><a href=\"${videoPath}\" target=\"_blank\"><img src=\"${posterImgPath}\"$5/></a></video>", $content, 1);
        }
    }

    $imgCache = array();
    $imgPattern = '#(href|src)="(https://obs\.line-scdn\.net/.+?)"#s';
    if(preg_match_all($imgPattern, $content, $m) > 0){
        for($i = 0; $i < count($m[0]); $i++){
            $url = preg_replace('|/(small)?$|s', '', $m[2][$i]);
            if(array_key_exists($url, $imgCache)){
                $imgPath = $imgCache[$url];
            }else{
                $imgPath = saveMedia($url, $date, $mediaCount++);
                $imgCache = array_merge($imgCache, array($url => $imgPath));
            }

            $content = preg_replace($imgPattern, "$1=\"${imgPath}\"", $content, 1);
        }
    }

    return $content;
}

function saveMedia(string $url, string $date, int $mediaCount): string{
    $data = safeFileGet($url, true);
    $media = $data[0];
    $http_header = $data[1];
    for($i = 0; $i < count($http_header); $i++){
        if(($mimeType = preg_replace('/^Content-Type: (image|video)\//', '', $http_header[$i])) !== $http_header[$i]){
            $mediaExt = ($mimeType === 'jpeg') ? 'jpg' : $mimeType;
            break;
        }
    }
    $mediaPath = MEMBER_DIR_ABS . "/${date}-${mediaCount}.${mediaExt}";

    if(!file_exists($mediaPath)){
        file_put_contents($mediaPath, $media);
    }
    return MEMBER_DIR_REL . "/${date}-${mediaCount}.${mediaExt}";
}

function safeFileGet(string $url, bool $includeResponseHeader = false){
    while(true){
        sleep(1);
        $data = @file_get_contents($url);
        if($data === false){
            sleep(1);
            continue;
        }
        if($includeResponseHeader){
            return [$data, $http_response_header];
        }else{
            return $data;
        }
    }
}

