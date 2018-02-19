<?php
if(!isset($argv[1])){
    echo "Please set memberName\n";
    echo "php {$argv[0]} MEMBER_NAME\n";
    die();
}

date_default_timezone_set('Asia/Tokyo');

$memberName = $argv[1];
$url = "https://blog-api.line-apps.com/v1/blog/${memberName}/articles?withBlog=1&pageKey=";

$db = new SQLite3("./${memberName}.db");
$db->exec("CREATE TABLE IF NOT EXISTS ${memberName} (id INTEGER, title TEXT, createdAt INTEGER, plain TEXT, content TEXT, UNIQUE(id))");

$lastId = $db->querySingle("SELECT max(id) FROM ${memberName}");
$lastId = ($lastId === NULL) ? 0 : $lastId;

$articles = array();

for($i = 1; ; $i++){
    $str = file_get_contents($url . $i);
    $json = json_decode($str, true);

    if(!isRequestSuccess($json)){
        echo "Error. Server responsed not 200.\npageKey is ${i}\nExit...";
        die();
    }

    $breakFlag = false;

    for($j = 0; $j < count($json['data']['rows']); $j++){
        $current = $json['data']['rows'][$j];

        $id = trim($current['id']);
        $title = trim($current['title']);
        $createdAt = trim($current['createdAt']);
        $plain = trim($current['plain']);
        $content = trim(getArticleContent($current['body'], $memberName, $createdAt));

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
    }

    if(!isNextPageExists($json)){
        break;
    }

    if($breakFlag){
        break;
    }
}

$articles = array_reverse($articles);
for($i = 0; $i < count($articles); $i++){
    $id = $articles[$i]['id'];
    $title = str_replace("'", "''", $articles[$i]['title']);
    $createdAt = $articles[$i]['createdAt'];
    $plain = str_replace("'", "''", $articles[$i]['plain']);
    $content = str_replace("'", "''", $articles[$i]['content']);

    $sql = "INSERT INTO ${memberName} VALUES (${id}, '${title}', ${createdAt}, '${plain}', '${content}')";
    $db->exec($sql);
}
echo "Finished!\n";
echo 'Add count: ' . count($articles) . "\n";



function isRequestSuccess($json){
    return $json['status'] === 200;
}

function isNextPageExists($json){
    return isset($json['data']['nextPageKey']);
}


function getArticleContent($body, $memberName, $createdAt){
    if(!file_exists("./${memberName}")){
        mkdir("./${memberName}");
    }

    $date = date('Y-m-d H.i.s', $createdAt);
    $content = replaceMediaUrl($body, $memberName, $date);
    return $content;
}

function replaceMediaUrl($content, $memberName, $date){
    $mediaCount = 1;
    $instagramImgPattern = '|(<div\s+?class="embed-instagram-media">)<a\s+?href="(https://www\.instagram\.com/p/.+?/)"(.*?)><img\s+?src="https://scontent\.cdninstagram\.com/.+?"(.*?)/></a></div>|s';
    if(preg_match_all($instagramImgPattern, $content, $m) > 0){
        for($i = 0; $i < count($m[0]); $i++){
            $mediaPath = saveMedia("{$m[2][$i]}media/?size=l", $memberName, $date, $mediaCount++);

            $content = preg_replace($instagramImgPattern, "$1<a href=\"${mediaPath}\"$3><img src=\"${mediaPath}\"$4/></a></div>", $content, 1);
        }
    }

    $instagramVideoPattern = '|<video\s+?controls(.*?)poster="(https://scontent\.cdninstagram\.com/.+?)"(.*?)><source\s+?src="(https://scontent\.cdninstagram\.com/.+?)"></source><a.+?href=.+?><img.+?src=".+?"(.*?)/></a></video>|s';
    if(preg_match_all($instagramVideoPattern, $content, $m) > 0){
        for($i = 0; $i < count($m[0]); $i++){
            $posterImgPath = saveMedia($m[2][$i], $memberName, $date, $mediaCount++);
            $videoPath = saveMedia($m[4][$i], $memberName, $date, $mediaCount++);

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
                $imgPath = saveMedia($url, $memberName, $date, $mediaCount++);
                $imgCache = array_merge($imgCache, array($url => $imgPath));
            }

            $content = preg_replace($imgPattern, "$1=\"${imgPath}\"", $content, 1);
        }
    }

    return $content;
}

function saveMedia($url, $memberName, $date, $mediaCount){
    $media = file_get_contents($url);
    $http_header = $http_response_header;
    for($i = 0; $i < count($http_header); $i++){
        if(($mimeType = preg_replace('/^Content-Type: (image|video)\//', '', $http_header[$i])) !== $http_header[$i]){
            $mediaExt = ($mimeType === 'jpeg') ? 'jpg' : $mimeType;
            break;
        }
    }
    $mediaPath = "./${memberName}/${date}-${mediaCount}.${mediaExt}";

    if(!file_exists($mediaPath)){
        file_put_contents($mediaPath, $media);
    }
    return $mediaPath;
}

