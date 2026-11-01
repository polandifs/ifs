<?php
declare(strict_types=1);

function fb_group_id(string $url): string
{
    $parts = parse_url(trim($url));
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || !in_array(strtolower((string)($parts['host'] ?? '')), ['facebook.com', 'www.facebook.com'], true)
        || !preg_match('~^/groups/([0-9]{5,25})/?$~', (string)($parts['path'] ?? ''), $match)) {
        throw new InvalidArgumentException('Podaj adres grupy w formacie https://www.facebook.com/groups/NUMER/');
    }
    return $match[1];
}

function fb_post_url(string $url, string $groupId): string
{
    $parts = parse_url(trim($url));
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || !in_array(strtolower((string)($parts['host'] ?? '')), ['facebook.com', 'www.facebook.com'], true)
        || !preg_match('~^/groups/([A-Za-z0-9_.-]{3,60})/posts/([0-9]{5,25})/?$~', (string)($parts['path'] ?? ''), $match)
        || (ctype_digit($match[1]) && $match[1] !== $groupId)) {
        throw new InvalidArgumentException('Podaj bezpośredni link do posta z wybranej grupy w formacie /groups/GRUPA/posts/NUMER.');
    }
    return 'https://www.facebook.com/groups/' . $groupId . '/posts/' . $match[2];
}

function fb_clean(string $text): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
}

/** Read only public HTML. Never use account cookies or follow a login redirect. */
function fb_scan_group(PDO $db, array $group): array
{
    $ch = curl_init($group['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 18,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_MAXFILESIZE => 4000000,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; IFSCamperFinder/1.0)',
    ]);
    $html = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $location = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $curlError = curl_error($ch);
    curl_close($ch);
    $found = 0;
    $added = 0;
    if (strpos($location, 'facebook.com/login') !== false || strpos($location, '/login/') !== false) {
        $status = 'Facebook kieruje serwer do logowania; posty nie są pobierane';
    } elseif ($statusCode !== 200 || !is_string($html)) {
        $status = 'Brak dostępu do publicznej strony (HTTP ' . $statusCode . ')';
        if ($curlError !== '') $status .= ': ' . mb_substr($curlError, 0, 70);
    } elseif (stripos($html, 'Please log in to see this page') !== false || stripos($html, 'Zaloguj się, aby zobaczyć') !== false) {
        $status = 'Facebook pokazuje stronę logowania; posty nie są pobierane';
    } else {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);
        $seen = [];
        $insert = $db->prepare("INSERT OR IGNORE INTO discoveries(url,title,country,source,price_text,signal) VALUES (?,?,'PL','Facebook (publiczna grupa)',?,?)");
        if ($loaded) foreach ($xp->query('//a[contains(@href,"/groups/") and contains(@href,"/posts/")]') as $anchor) {
            $href = html_entity_decode($anchor->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('~/groups/' . preg_quote($group['id'], '~') . '/posts/([0-9]{5,25})~', $href, $match)) continue;
            $url = 'https://www.facebook.com/groups/' . $group['id'] . '/posts/' . $match[1];
            if (isset($seen[$url])) continue;
            $seen[$url] = true;
            $card = $anchor;
            for ($i = 0; $i < 8 && $card->parentNode; $i++) {
                $card = $card->parentNode;
                if ($card instanceof DOMElement && $card->getAttribute('role') === 'article') break;
            }
            if (!($card instanceof DOMElement) || $card->getAttribute('role') !== 'article') continue;
            $text = fb_clean($card->textContent);
            if (!preg_match('/kamper|camper|kampervan|przyczep|caravan/ui', $text)
                || !preg_match('/sprzedam|sprzedaż|na sprzedaż|do sprzedania|odstąpię|cena/ui', $text)
                || preg_match('/\b(kupię|kupimy|poszukuję|wynajmę|do wynajęcia|części)\b/ui', $text)) continue;
            $title = mb_substr($text, 0, 180);
            if (mb_strlen($title) < 20) continue;
            $price = '';
            if (preg_match('/(?<!\d)(?:\d{1,3}(?:[ .\x{00A0}]\d{3})+|\d{4,7})\s?(?:zł|PLN)/u', $text, $priceMatch)) $price = fb_clean($priceMatch[0]);
            $found++;
            $insert->execute([$url, $title, $price, 'Publiczny wpis z grupy ' . $group['id'] . '; treść, cena i dostępność do sprawdzenia']);
            $added += $insert->rowCount();
            if ($found >= 40) break;
        }
        $status = $found ? 'Publiczny odczyt: ' . $found . ' możliwych ofert; ' . $added . ' nowych'
            : 'Strona dostępna, ale nie udostępnia postów do publicznego odczytu';
    }
    $update = $db->prepare('UPDATE facebook_groups SET checked_at=CURRENT_TIMESTAMP, status=? WHERE id=?');
    $update->execute([$status, $group['id']]);
    return [$status, $found, $added];
}
