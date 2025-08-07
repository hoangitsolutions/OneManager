<?php
class Googledrive {
    private $client_id;
    private $client_secret;
    private $access_token;
    private $refresh_token;
    private $expires_in;
    private $disktag;
    private $oauth_url = 'https://accounts.google.com/o/oauth2/v2/auth';
    private $token_url = 'https://oauth2.googleapis.com/token';
    private $api_url = 'https://www.googleapis.com/drive/v3';
    private $scope = 'https://www.googleapis.com/auth/drive';
    private $download_url = '@google.drive.downloadUrl';
    private $path = '/drive/root';

    public function __construct($tag) {
        $this->disktag = $tag;
        $config = getConfig($tag);
        $this->client_id = $config['client_id'] ?? 'default_client_id';
        $this->client_secret = $config['client_secret'] ?? 'default_client_secret';
        $this->refresh_token = $config['refresh_token'] ?? '';
        $this->path = $config['path'] ?? $this->path;
        $this->access_token = $this->get_access_token($this->refresh_token);
    }

    private function get_access_token($refresh_token) {
        $cache_key = "google_access_token_{$this->disktag}";
        $cached = getcache($cache_key);
        if ($cached && $cached['expires_in'] > time()) {
            return $cached['access_token'];
        }

        $data = [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'refresh_token' => $refresh_token,
            'grant_type' => 'refresh_token'
        ];

        for ($i = 0; $i < 3; $i++) {
            $response = $this->GAPI('POST', $this->token_url, $data);
            if ($response['code'] == 200) {
                $token_data = json_decode($response['body'], true);
                $this->access_token = $token_data['access_token'];
                $this->refresh_token = $token_data['refresh_token'] ?? $this->refresh_token;
                $this->expires_in = time() + $token_data['expires_in'] - 60;
                savecache($cache_key, [
                    'access_token' => $this->access_token,
                    'expires_in' => $this->expires_in
                ], $this->disktag);
                return $this->access_token;
            }
            sleep(1);
        }
        throw new Exception('Failed to obtain access token');
    }

    private function GAPI($method, $url, $data = [], $headers = []) {
        $headers[] = 'Authorization: Bearer ' . $this->access_token;
        $headers[] = 'Content-Type: application/json';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($data && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 429) {
            $retry_after = 10; // Default retry-after for rate limiting
            saveConfig(['activeLimit' => time() + $retry_after], $this->disktag);
            sleep($retry_after);
            return $this->GAPI($method, $url, $data, $headers);
        }

        return ['code' => $http_code, 'body' => $response];
    }

    public function list_files($path) {
        $cache_key = "google_list_{$this->disktag}_" . md5($path);
        $cached = getcache($cache_key);
        if ($cached) {
            return $cached;
        }

        $files = $this->fetch_files_children($path, '');
        savecache($cache_key, $files, $this->disktag);
        return $files;
    }

    private function fetch_files_children($path, $pageToken = '') {
        $folder_id = $this->get_file_id($path);
        $query = "parents='$folder_id' and trashed=false";
        $url = $this->api_url . '/files?q=' . urlencode($query) . '&fields=nextPageToken,files(id,name,mimeType,size,modifiedTime,parents,' . $this->download_url . ')';
        if ($pageToken) {
            $url .= '&pageToken=' . urlencode($pageToken);
        }

        $response = $this->GAPI('GET', $url);
        if ($response['code'] != 200) {
            return ['error' => 'Failed to list files'];
        }

        $data = json_decode($response['body'], true);
        $files = [];
        foreach ($data['files'] as $file) {
            $files[] = $this->files_format($file);
        }

        if (isset($data['nextPageToken'])) {
            $next_files = $this->fetch_files_children($path, $data['nextPageToken']);
            $files = array_merge($files, $next_files);
        }

        return $files;
    }

    private function get_file_id($path) {
        if ($path == '/') {
            return 'root';
        }
        $parts = explode('/', trim($path, '/'));
        $current_id = 'root';
        foreach ($parts as $part) {
            $query = "name='$part' and '$current_id' in parents and trashed=false";
            $url = $this->api_url . '/files?q=' . urlencode($query) . '&fields=files(id)';
            $response = $this->GAPI('GET', $url);
            if ($response['code'] == 200) {
                $data = json_decode($response['body'], true);
                if (!empty($data['files'])) {
                    $current_id = $data['files'][0]['id'];
                } else {
                    throw new Exception('Path not found: ' . $path);
                }
            } else {
                throw new Exception('Failed to resolve path: ' . $path);
            }
        }
        return $current_id;
    }

    private function files_format($file) {
        $is_folder = $file['mimeType'] == 'application/vnd.google-apps.folder';
        $ext = $is_folder ? '' : pathinfo($file['name'], PATHINFO_EXTENSION);
        $result = [
            'id' => $file['id'],
            'name' => $file['name'],
            'type' => $is_folder ? 'folder' : 'file',
            'path' => '', // To be set by caller if needed
            'ext' => $ext,
            'size' => $file['size'] ?? 0,
            'time' => strtotime($file['modifiedTime']),
            'url' => $file[$this->download_url] ?? ''
        ];
        if (!$is_folder && $file['size'] < 1000000) {
            $content = $this->get_file_content($file['id']);
            if ($content) {
                $result['content'] = $content;
            }
        }
        return $result;
    }

    private function get_file_content($file_id) {
        $url = $this->api_url . "/files/$file_id?alt=media";
        $response = $this->GAPI('GET', $url);
        if ($response['code'] == 200) {
            $content = $response['body'];
            $encoding = chkTxtCode($content);
            if ($encoding && $encoding != 'UTF-8') {
                $content = iconv($encoding, 'UTF-8', $content);
            }
            return $content;
        }
        return '';
    }

    public function Rename($file_id, $newname) {
        $url = $this->api_url . "/files/$file_id";
        $data = ['name' => $newname];
        $response = $this->GAPI('PATCH', $url, $data);
        return $response['code'] == 200;
    }

    public function Delete($file_id) {
        $url = $this->api_url . "/files/$file_id";
        $response = $this->GAPI('DELETE', $url);
        return $response['code'] == 204;
    }

    public function Move($file_id, $new_parent_id) {
        $url = $this->api_url . "/files/$file_id?addParents=$new_parent_id&removeParents=" . $this->get_file_parent($file_id);
        $response = $this->GAPI('PATCH', $url, []);
        return $response['code'] == 200;
    }

    private function get_file_parent($file_id) {
        $url = $this->api_url . "/files/$file_id?fields=parents";
        $response = $this->GAPI('GET', $url);
        if ($response['code'] == 200) {
            $data = json_decode($response['body'], true);
            return $data['parents'][0] ?? 'root';
        }
        throw new Exception('Failed to get parent ID');
    }

    public function Copy($file_id) {
        $url = $this->api_url . "/files/$file_id/copy";
        $file_info = $this->get_file_info($file_id);
        $new_name = $file_info['name'] . '_' . date('YmdHis');
        $data = ['name' => $new_name];
        $response = $this->GAPI('POST', $url, $data);
        return $response['code'] == 200;
    }

    private function get_file_info($file_id) {
        $url = $this->api_url . "/files/$file_id?fields=name,mimeType";
        $response = $this->GAPI('GET', $url);
        if ($response['code'] == 200) {
            return json_decode($response['body'], true);
        }
        throw new Exception('Failed to get file info');
    }

    public function Edit($file_id, $content) {
        $url = $this->api_url . "/files/$file_id?alt=media";
        $response = $this->GAPI('PUT', $url, $content, ['Content-Type: text/plain']);
        return $response['code'] == 200;
    }

    public function Create($parent_id, $type, $name, $content = '') {
        $url = $this->api_url . '/files';
        $data = [
            'name' => $name,
            'parents' => [$parent_id],
            'mimeType' => $type == 'folder' ? 'application/vnd.google-apps.folder' : 'application/octet-stream'
        ];
        $response = $this->GAPI('POST', $url, $data);
        if ($response['code'] == 200 && $content && $type != 'folder') {
            $file_id = json_decode($response['body'], true)['id'];
            return $this->Edit($file_id, $content);
        }
        return $response['code'] == 200;
    }

    public function Encrypt($folder_id, $passfilename, $pass) {
        $url = $this->api_url . '/files';
        $query = "name='$passfilename' and '$folder_id' in parents and trashed=false";
        $response = $this->GAPI('GET', $url . '?q=' . urlencode($query));
        $exists = json_decode($response['body'], true)['files'] ? true : false;

        if ($pass) {
            if ($exists) {
                return true;
            }
            return $this->Create($folder_id, 'file', $passfilename, $pass);
        } else {
            if (!$exists) {
                return true;
            }
            $file_id = json_decode($response['body'], true)['files'][0]['id'];
            return $this->Delete($file_id);
        }
    }

    public function smallfileupload($path, $tmpfile) {
        $parent_id = $this->get_file_id(dirname($path));
        $filename = basename($path);
        $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=media';
        $response = $this->GAPI('POST', $url, file_get_contents($tmpfile), [
            'Content-Type: ' . mime_content_type($tmpfile),
            'X-Upload-Content-Length: ' . filesize($tmpfile)
        ]);
        if ($response['code'] == 200) {
            $file_id = json_decode($response['body'], true)['id'];
            return $this->Rename($file_id, $filename);
        }
        return false;
    }

    public function bigfileupload($path) {
        $parent_id = $this->get_file_id(dirname($path));
        $filename = basename($path);
        $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable';
        $data = ['name' => $filename, 'parents' => [$parent_id]];
        $response = $this->GAPI('POST', $url, $data, ['X-Upload-Content-Type: application/octet-stream']);
        if ($response['code'] == 200) {
            $upload_url = $response['headers']['Location'];
            savecache("upload_{$path}", ['url' => $upload_url, 'offset' => 0], $this->disktag);
            return $upload_url;
        }
        return false;
    }

    public function getDiskSpace() {
        $url = $this->api_url . '/about?fields=storageQuota';
        $response = $this->GAPI('GET', $url);
        if ($response['code'] == 200) {
            $data = json_decode($response['body'], true)['storageQuota'];
            $used = $data['usage'] ?? 0;
            $total = $data['limit'] ?? 15000000000; // Default to 15GB for free accounts
            return ['used' => $used, 'total' => $total, 'unit' => 'byte'];
        }
        return ['error' => 'Failed to get disk space'];
    }

    public function get_thumbnails_url($path) {
        $file_id = $this->get_file_id($path);
        $url = $this->api_url . "/files/$file_id?fields=thumbnailLink";
        $response = $this->GAPI('GET', $url);
        if ($response['code'] == 200) {
            $data = json_decode($response['body'], true);
            return $data['thumbnailLink'] ?? '';
        }
        return '';
    }

    public function AddDisk() {
        if (!isset($_GET['code'])) {
            $auth_url = $this->oauth_url . '?client_id=' . urlencode($this->client_id) . '&redirect_uri=' . urlencode(getConfig('redirect_uri')) . '&response_type=code&scope=' . urlencode($this->scope) . '&access_type=offline&prompt=consent';
            header('Location: ' . $auth_url);
            exit;
        } else {
            $data = [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'code' => $_GET['code'],
                'grant_type' => 'authorization_code',
                'redirect_uri' => getConfig('redirect_uri')
            ];
            $response = $this->GAPI('POST', $this->token_url, $data);
            if ($response['code'] == 200) {
                $token_data = json_decode($response['body'], true);
                saveConfig([
                    'refresh_token' => $token_data['refresh_token'],
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret
                ], $this->disktag);
                return 'Disk added successfully';
            }
            return 'Failed to add disk';
        }
    }

    public function get_shared_drive_id($drive_name) {
        $url = $this->api_url . '/drives';
        $response = $this->GAPI('GET', $url);
        if ($response['code'] == 200) {
            $drives = json_decode($response['body'], true)['drives'];
            foreach ($drives as $drive) {
                if ($drive['name'] == $drive_name) {
                    return $drive['id'];
                }
            }
        }
        throw new Exception('Shared drive not found: ' . $drive_name);
    }
}
?>
