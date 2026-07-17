<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class CustomerImportService
{
    public const HEADERS = ['name','email','phone','address1','address2','city','state','zip','age_verified','age_verified_date','age_verified_source','marketing_opt_in','consent_date','consent_source','notes'];

    public static function template(): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="customer-import-template.csv"');
        header('Cache-Control: no-store, private');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::HEADERS);
        fputcsv($out, ['Sample Customer','sample@example.com','6315550100','123 Main St','','Huntington','NY','11743','yes','2026-01-15','in-person ID check','no','','','Optional note for a new record']);
        fclose($out);
    }

    public static function preview(array $upload, int $userId): array
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmp = (string) ($upload['tmp_name'] ?? '');
        $isTrustedUpload = is_uploaded_file($tmp) || ((string) getenv('APP_ENV') === 'testing' && is_file($tmp));
        if ($error !== UPLOAD_ERR_OK || !$isTrustedUpload) {
            throw new RuntimeException('Choose a CSV file exported from Excel.');
        }
        if ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
            throw new RuntimeException('The customer file must be 2 MB or smaller.');
        }
        $name = basename((string) ($upload['name'] ?? 'customers.csv'));
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            throw new RuntimeException('Export the spreadsheet as CSV before importing it.');
        }
        $handle = fopen((string) $upload['tmp_name'], 'rb');
        if (!$handle) {
            throw new RuntimeException('The uploaded file could not be read.');
        }
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            throw new RuntimeException('The CSV is empty.');
        }
        $headers = array_map(static fn($v) => strtolower(trim((string) $v, " \t\n\r\0\x0B\xEF\xBB\xBF")), $headers);
        if (count($headers) > 20 || !in_array('name', $headers, true)) {
            fclose($handle);
            throw new RuntimeException('Use the downloadable template and keep the name column.');
        }
        $unknown = array_diff($headers, self::HEADERS);
        if ($unknown) {
            fclose($handle);
            throw new RuntimeException('Unknown columns: ' . implode(', ', $unknown));
        }
        $rows = [];
        $errors = [];
        while (($raw = fgetcsv($handle)) !== false) {
            if (count($rows) >= 5000) {
                $errors[] = 'The 5,000-row import limit was reached.';
                break;
            }
            if (!array_filter($raw, static fn($v) => trim((string) $v) !== '')) {
                continue;
            }
            $raw = array_pad($raw, count($headers), '');
            $row = array_combine($headers, array_slice($raw, 0, count($headers)));
            foreach ($row as $key => $value) {
                $value = trim((string) $value);
                if ($key !== 'phone' && preg_match('/^[\s]*[=+\-@]/u', $value)) {
                    $errors[] = 'Row ' . (count($rows) + 2) . ' contains an unsafe spreadsheet formula.';
                    continue 2;
                }
                $row[$key] = mb_substr($value, 0, $key === 'notes' ? 1000 : 255);
            }
            $row += array_fill_keys(self::HEADERS, '');
            $row['email'] = strtolower($row['email']);
            $row['state'] = strtoupper($row['state'] ?: 'NY');
            $row['marketing_opt_in'] = in_array(strtolower($row['marketing_opt_in']), ['yes','true','1'], true) ? 'yes' : 'no';
            $row['age_verified'] = in_array(strtolower($row['age_verified']), ['yes','true','1'], true) ? 'yes' : 'no';
            if ($row['name'] === '' || ($row['email'] === '' && CustomerService::phoneKey($row['phone']) === null)) {
                $errors[] = 'Row ' . (count($rows) + 2) . ' needs a name and an email or phone.';
                continue;
            }
            if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Row ' . (count($rows) + 2) . ' has an invalid email.';
                continue;
            }
            if ($row['marketing_opt_in'] === 'yes' && ($row['consent_date'] === '' || $row['consent_source'] === '')) {
                $row['marketing_opt_in'] = 'no';
                $errors[] = 'Row ' . (count($rows) + 2) . ' was not opted in because consent date/source was missing.';
            }
            if ($row['marketing_opt_in'] === 'yes' && !self::validDate($row['consent_date'])) {
                $row['marketing_opt_in'] = 'no';
                $errors[] = 'Row ' . (count($rows) + 2) . ' was not opted in because the consent date is invalid or in the future.';
            }
            if ($row['age_verified'] === 'yes' && ($row['age_verified_date'] === '' || $row['age_verified_source'] === '')) {
                $row['age_verified'] = 'no';
                $errors[] = 'Row ' . (count($rows) + 2) . ' was not age-verified because verification date/source was missing.';
            }
            if ($row['age_verified'] === 'yes' && !self::validDate($row['age_verified_date'])) {
                $row['age_verified'] = 'no';
                $errors[] = 'Row ' . (count($rows) + 2) . ' was not age-verified because the verification date is invalid or in the future.';
            }
            $existing = $row['email'] !== '' ? Database::one('SELECT id FROM customer_profiles WHERE email_key=?', [$row['email']]) : null;
            if (!$existing && ($phone = CustomerService::phoneKey($row['phone']))) {
                $existing = Database::one('SELECT id FROM customer_profiles WHERE phone_key=?', [$phone]);
            }
            $row['_action'] = $existing ? 'update' : 'create';
            $row['_id'] = $existing['id'] ?? null;
            $rows[] = $row;
        }
        fclose($handle);
        if (!$rows) {
            throw new RuntimeException('No valid customer rows were found. ' . ($errors[0] ?? ''));
        }
        $payload = json_encode($rows, JSON_THROW_ON_ERROR);
        $token = self::storePayload($payload);
        Database::execute('INSERT INTO customer_import_jobs (original_filename,file_hash,total_rows,create_rows,update_rows,error_rows,summary_json,created_by_user_id) VALUES (?,?,?,?,?,?,?,?)', [
            $name, hash_file('sha256', (string) $upload['tmp_name']), count($rows), count(array_filter($rows, fn($r) => $r['_action'] === 'create')),
            count(array_filter($rows, fn($r) => $r['_action'] === 'update')), count($errors), json_encode(['errors' => array_slice($errors, 0, 20)]), $userId,
        ]);
        $jobId = (int) Database::pdo()->lastInsertId();
        $_SESSION['customer_import'] = ['job_id' => $jobId, 'token' => $token];
        return ['job_id' => $jobId, 'rows' => array_slice($rows, 0, 50), 'total' => count($rows), 'errors' => array_slice($errors, 0, 20)];
    }

    public static function confirm(int $userId): array
    {
        $session = $_SESSION['customer_import'] ?? [];
        $job = Database::one("SELECT * FROM customer_import_jobs WHERE id=? AND created_by_user_id=? AND status='previewed'", [(int) ($session['job_id'] ?? 0), $userId]);
        if (!$job || empty($session['token'])) {
            throw new RuntimeException('The import preview expired. Upload the file again.');
        }
        $rows = json_decode(self::readPayload((string) $session['token']), true, 512, JSON_THROW_ON_ERROR);
        $created = $updated = 0;
        foreach ($rows as $row) {
            $existing = !empty($row['_id']) ? Database::one('SELECT * FROM customer_profiles WHERE id=?', [(int) $row['_id']]) : null;
            $id = CustomerService::capture([
                'customer_id' => $existing['id'] ?? null, 'name' => $row['name'], 'email' => $row['email'], 'phone' => $row['phone'],
                'address1' => $existing ? ($existing['address1'] ?: $row['address1']) : $row['address1'],
                'address2' => $existing ? ($existing['address2'] ?: $row['address2']) : $row['address2'],
                'city' => $existing ? ($existing['city'] ?: $row['city']) : $row['city'],
                'state' => $existing ? ($existing['state'] ?: $row['state']) : $row['state'],
                'postal_code' => $existing ? ($existing['postal_code'] ?: $row['zip']) : $row['zip'],
            ]);
            if (!$id) continue;
            if ($row['marketing_opt_in'] === 'yes') {
                Database::execute("UPDATE customer_profiles SET marketing_opt_in=1,marketing_consent_at=?,marketing_consent_source=?,marketing_unsubscribed_at=NULL WHERE id=? AND marketing_unsubscribed_at IS NULL", [$row['consent_date'], $row['consent_source'], $id]);
            }
            if ($row['age_verified'] === 'yes') {
                Database::execute('UPDATE customer_profiles SET marketing_age_verified_at=?,marketing_age_verified_source=? WHERE id=?', [$row['age_verified_date'], $row['age_verified_source'], $id]);
            }
            if (!$existing && $row['notes'] !== '') {
                Database::execute('UPDATE customer_profiles SET private_notes=? WHERE id=?', [$row['notes'], $id]);
            }
            $existing ? $updated++ : $created++;
        }
        self::deletePayload((string) $session['token']);
        unset($_SESSION['customer_import']);
        Database::execute("UPDATE customer_import_jobs SET status='completed',create_rows=?,update_rows=?,completed_at=CURRENT_TIMESTAMP WHERE id=?", [$created, $updated, (int) $job['id']]);
        return ['created' => $created, 'updated' => $updated];
    }

    public static function cancel(int $userId): void
    {
        $session = $_SESSION['customer_import'] ?? [];
        if (!empty($session['token'])) self::deletePayload((string) $session['token']);
        Database::execute("UPDATE customer_import_jobs SET status='cancelled' WHERE id=? AND created_by_user_id=? AND status='previewed'", [(int) ($session['job_id'] ?? 0), $userId]);
        unset($_SESSION['customer_import']);
    }

    private static function storePayload(string $payload): string
    {
        $key = (string) getenv('APP_KEY');
        if (strlen($key) < 32) throw new RuntimeException('Set a random APP_KEY of at least 32 characters before importing private customer data.');
        $dir = APP_ROOT . '/storage/imports';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        @chmod($dir, 0700);
        $token = bin2hex(random_bytes(24));
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($payload, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('Private import staging could not be encrypted.');
        $path = $dir . '/' . $token . '.dat';
        file_put_contents($path, $iv . $tag . $cipher, LOCK_EX);
        @chmod($path, 0600);
        return $token;
    }

    private static function readPayload(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) throw new RuntimeException('Invalid import token.');
        $data = @file_get_contents(APP_ROOT . '/storage/imports/' . $token . '.dat');
        if ($data === false || strlen($data) < 29) throw new RuntimeException('The private import preview expired.');
        $plain = openssl_decrypt(substr($data, 28), 'aes-256-gcm', hash('sha256', (string) getenv('APP_KEY'), true), OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));
        if ($plain === false) throw new RuntimeException('The private import preview could not be decrypted.');
        return $plain;
    }

    private static function deletePayload(string $token): void
    {
        if (preg_match('/^[a-f0-9]{48}$/', $token)) @unlink(APP_ROOT . '/storage/imports/' . $token . '.dat');
    }

    private static function validDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts)) return false;
        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) return false;
        return $value >= '2000-01-01' && $value <= date('Y-m-d');
    }
}
