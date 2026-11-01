<?php
declare(strict_types=1);

function homes_send_email(string $subject, string $body): bool {
    $recipient = 'mieszkania@ifs-poland.com';
    $headers = [
        'From: IFS Mieszkania <mieszkania@ifs-poland.com>',
        'Reply-To: mieszkania@ifs-poland.com',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    return mail($recipient, $subject, $body, implode("\r\n", $headers), '-f' . $recipient);
}

function homes_email_alerts(array $offers): bool {
    if (!$offers) return true;
    $subject = 'IFS: zmiany ofert mieszkan w Kwidzynie (' . count($offers) . ')';
    $lines = [
        'Monitor mieszkań na sprzedaż w Kwidzynie',
        'Wykryte zmiany: ' . count($offers),
        'Skan wykonywany co 5 minut; aktualność ogłoszenia potwierdź w portalu.',
        '',
    ];
    foreach (array_slice($offers, 0, 100) as $offer) {
        $lines[] = (string)($offer['title'] ?? 'Mieszkanie w Kwidzynie');
        if (!empty($offer['price'])) $lines[] = number_format((int)$offer['price'], 0, ',', ' ') . ' zł';
        $lines[] = (string)($offer['url'] ?? '');
        $lines[] = '';
    }
    $lines[] = 'Panel: https://ifs-poland.com/ifskampery/mieszkania/';
    return homes_send_email($subject, implode("\r\n", $lines));
}
