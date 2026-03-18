<?php

namespace app\service;


use app\model\GameType;
use app\model\MachineMedia;
use app\model\MachineMediaPush;
use app\model\MachineRecording;
use app\model\MachineTencentPlay;
use Exception;
use support\Db;
use support\Log;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Live\V20180801\LiveClient;
use TencentCloud\Live\V20180801\Models\DescribeLiveStreamStateRequest;
use TencentCloud\Live\V20180801\Models\DescribeStreamPlayInfoListRequest;
use TencentCloud\Live\V20180801\Models\ForbidLiveStreamRequest;
use TencentCloud\Live\V20180801\Models\ResumeLiveStreamRequest;
use WebmanTech\LaravelHttpClient\Facades\Http;

class MediaServer
{

    public $method = 'POST';
    public $log;
    private $domain = '';
    private $mediaApp = '';
    private $stream_url = 'rtsp://admin:ez88888888@stream_url/cam/realmonitor?channel=1&subtype=0';
    private $fish_stream_url = 'rtsp://{ip}/live/0';

    /**
     */
    public function __construct($domain = '', $mediaApp = '')
    {
        $this->domain = $domain;
        $this->mediaApp = $mediaApp;
        $this->log = Log::channel('media_recording');
    }

    /**
     * 添加rtmp节点
     * @param $rtmpUrl
     * @param $endpointServiceId
     * @param $streamName
     * @param int $attempts
     * @return true
     * @throws Exception
     */
    public function rtmpEndpoint($rtmpUrl, $endpointServiceId, $streamName, int $attempts = 0): bool
    {
        $maxRetries = 4;
        try {
            $response = Http::timeout(5)->post($this->domain . '/' . $this->mediaApp . '/rest/v2/broadcasts/' . $streamName . '/rtmp-endpoint',
                [
                    'type' => 'generic',
                    'rtmpUrl' => $rtmpUrl,
                    'endpointServiceId' => $endpointServiceId,
                ]);
        } catch (\Exception) {
            throw new Exception(trans('media.media_request_error', [], 'message'));
        }

        $this->log->info('rtmpEndpoint', [
            $response,
            $this->domain . '/' . $this->mediaApp . '/rest/v2/broadcasts/' . $streamName . '/rtmp-endpoint',
            'type' => 'generic',
            'rtmpUrl' => $rtmpUrl,
            'endpointServiceId' => $endpointServiceId,
        ]);
        if (!empty($response) && $response->status() == 200) {
            $response = json_decode($response, true);
            if (empty($response['success'])) {
                $attempts++;
                if ($attempts >= $maxRetries) {
                    throw new Exception(trans('media.media_stream_end_point_error', [], 'message'));
                }
                $this->rtmpEndpoint($rtmpUrl, $endpointServiceId, $streamName, $attempts);
            }
        } else {
            throw new Exception(trans('media.media_request_error', [], 'message'));
        }

        return true;
    }

    /**
     * 删除rtmp节点
     * @param $endpointServiceId
     * @param $streamName
     * @param int $attempts
     * @return true
     * @throws Exception
     */
    public function deleteRtmpEndpoint($endpointServiceId, $streamName, int $attempts = 0): bool
    {
        $maxRetries = 4;
        try {
            $response = Http::timeout(5)->delete($this->domain . '/' . $this->mediaApp . '/rest/v2/broadcasts/' . $streamName . '/rtmp-endpoint?endpointServiceId=' . $endpointServiceId);
        } catch (\Exception) {
            throw new Exception(trans('media.media_request_error', [], 'message'));
        }

        $this->log->info('deleteRtmpEndpoint', [$response, $endpointServiceId, $streamName]);
        if (!empty($response) && $response->status() == 200) {
            $response = json_decode($response, true);
            if (empty($response['success']) && !$response['success']) {
                $attempts++;
                if ($attempts >= $maxRetries) {
                    throw new Exception(trans('media.delete_media_stream_end_point_error', [], 'message'));
                }
                $this->deleteRtmpEndpoint($endpointServiceId, $streamName, $attempts);
            }
        } else {
            throw new Exception(trans('media.media_request_error', [], 'message'));
        }

        return true;
    }

    /**
     * 获取流信息
     * @param $streamName
     * @return false|mixed
     */
    public function getViewers($streamName): mixed
    {
        try {
            $response = Http::timeout(5)->asJson()->get($this->domain . '/' . $this->mediaApp . '/rest/v2/broadcasts/' . $streamName);
        } catch (\Exception) {
            return false;
        }
        if (!empty($response) && $response->status() == 200) {
            $response = json_decode($response, true);
            if (empty($response['success'])) {
                if (isset($response['webRTCViewerCount'])) {
                    return $response['webRTCViewerCount'];
                }
            }
        }

        return false;
    }

    /**
     * 获取播放流
     * @param MachineRecording $machineRecording
     * @return string
     * @throws \Exception
     */
    public function getRecording(MachineRecording $machineRecording): string
    {
        if (!empty($machineRecording->vod_name)) {
            return 'http://' . $machineRecording->media->pull_ip . '/' . $machineRecording->media->media_app . '/streams/' . $machineRecording->vod_name;
        }
        $response = Http::timeout(5)->asJson()->get($machineRecording->media->push_ip . '/' . $machineRecording->media->media_app . '/rest/v2/vods/' . $machineRecording->data_id);
        if (!empty($response) && $response->status() == 200) {
            $orgData = $response;
            $response = json_decode($response, true);
            $this->log->info('getRecording', [$response]);
            if (empty($response['vodName'])) {
                throw new \Exception(trans('vod_file_not_found', [], 'message'));
            }
            $machineRecording->org_data = $orgData;
            $machineRecording->vod_name = $response['vodName'];
            $machineRecording->save();
        } else {
            throw new \Exception(trans('vod_file_not_found', [], 'message'));
        }

        return 'http://' . $machineRecording->media->pull_ip . '/' . $machineRecording->media->media_app . '/streams/' . $machineRecording->vod_name;
    }

    /**
     * 删除视频
     * @param MachineRecording $machineRecording
     * @return true
     * @throws \Exception
     */
    public function deleteRecording(MachineRecording $machineRecording): bool
    {
        try {
            $response = Http::timeout(5)->asJson()->delete($machineRecording->media->push_ip . '/' . $machineRecording->media->media_app . '/rest/v2/vods/' . $machineRecording->data_id);
        } catch (\Exception) {
            throw new Exception(trans('media.media_request_error', [], 'message'));
        }
        $this->log->info('deleteRecording', [$response]);
        if (!empty($response) && $response->status() == 200) {
            $machineRecording->delete();
        } else {
            throw new \Exception(trans('vod_file_not_found', [], 'message'));
        }

        return true;
    }

    /**
     * 开始录制
     * @param MachineMedia $media
     * @param int $type
     * @param int $departmentId
     * @param int $recordId
     * @param int $logId
     * @return bool
     * @throws \Exception
     */
    public function startRecording(
        MachineMedia $media,
        int          $type = MachineRecording::TYPE_TEST,
        int          $departmentId = 1,
        int          $recordId = 0,
        int          $logId = 0
    ): bool
    {
        if (MachineRecording::query()->where('machine_id', $media->machine_id)->where('status',
            MachineRecording::STATUS_STARTING)->exists()) {
            $this->stopRecording($media);
        }
        $machineRecording = new MachineRecording();
        $machineRecording->type = $type;
        $machineRecording->machine_id = $media->machine_id;
        $machineRecording->machine_code = $media->machine->code;
        $machineRecording->machine_name = $media->machine->name;
        $machineRecording->media_id = $media->id;
        $machineRecording->department_id = $departmentId;
        $machineRecording->player_game_record_id = $recordId;
        $machineRecording->player_game_log_id = $logId;
        $machineRecording->start_time = date('Y-m-d H:i:s');
        try {
            $response = Http::timeout(5)->asJson()->put($media->push_ip . '/' . $media->media_app . '/rest/v2/broadcasts/' . $media->stream_name . '/recording/true?recordType=mp4&name=' . $media->stream_name . uniqid());
        } catch (\Exception) {
            throw new Exception(trans('media.media_request_error', [], 'message'));
        }
        if (!empty($response) && $response->status() == 200) {
            $response = json_decode($response, true);
            $this->log->info('startRecording', [$response]);
            if (empty($response['success'])) {
                $machineRecording->status = MachineRecording::STATUS_FAIL;
                $machineRecording->save();
                throw new \Exception(trans('stop_media_recording_fail', [], 'message'));
            }
            $machineRecording->data_id = $response['dataId'];
        } else {
            $machineRecording->status = MachineRecording::STATUS_FAIL;
            $machineRecording->save();
            throw new \Exception(trans('media_sever_fail', [], 'message'));
        }
        $machineRecording->status = MachineRecording::STATUS_STARTING;
        $machineRecording->save();

        return true;
    }

    /**
     * 停止录制
     * @param MachineMedia $media
     * @return bool
     * @throws \Exception
     */
    public function stopRecording(MachineMedia $media): bool
    {
        try {
            $response = Http::timeout(5)->asJson()->put($media->push_ip . '/' . $media->media_app . '/rest/v2/broadcasts/' . $media->stream_name . '/recording/false?recordType=mp4');
        } catch (\Exception) {
            throw new Exception(trans('media.media_request_error', [], 'message'));
        }
        if (!empty($response) && $response->status() == 200) {
            $response = json_decode($response, true);
            $this->log->info('stopRecording', [$response]);
            /** @var MachineRecording $startRecording */
            $startRecording = MachineRecording::query()
                ->where('media_id', $media->id)
                ->where('data_id', $response['dataId'])
                ->where('status', MachineRecording::STATUS_STARTING)
                ->first();
            if (empty($response['success'])) {
                if (!empty($startRecording)) {
                    $startRecording->status = MachineRecording::STATUS_FAIL;
                    $startRecording->save();
                }
            } else {
                if (!empty($startRecording)) {
                    $startRecording->status = MachineRecording::STATUS_COMPLETE;
                    $startRecording->end_time = date('Y-m-d H:i:s');
                    $startRecording->save();
                }
            }
            return true;
        }

        return false;
    }

    /**
     * 获取腾讯流观看人数
     * @param MachineMediaPush $machineMediaPush
     * @return int
     * @throws \Exception
     */
    public function getTencentViewers(MachineMediaPush $machineMediaPush): int
    {
        try {
            $cred = new Credential($machineMediaPush->machineTencentPlay->api_appid,
                $machineMediaPush->machineTencentPlay->api_key);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("live.tencentcloudapi.com");
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new LiveClient($cred, "", $clientProfile);
            $req = new DescribeStreamPlayInfoListRequest();
            $params = [
                'StreamName' => $machineMediaPush->machine_code . '_' . $machineMediaPush->endpoint_service_id,
                'StartTime' => date('Y-m-d H:i:s', strtotime('-2 minute')),
                'EndTime' => date('Y-m-d H:i:s', strtotime('-1 minute')),
                'ServiceName' => 'LEB',
            ];
            $req->fromJsonString(json_encode($params));
            $resp = $client->DescribeStreamPlayInfoList($req)->DataInfoList;
            $lastItem = end($resp);
        } catch (TencentCloudSDKException $e) {
            $this->log->error('getTencentViewers', [$e->getMessage()]);
            throw new \Exception($e->getMessage());
        }
        return $lastItem->Online;
    }

    /**
     * 恢复流推流
     * @param $machineCode
     * @return int
     * @throws \Exception
     */
    public function resumeLiveStream($machineCode): int
    {
        /** @var MachineTencentPlay $machineTencentPlay */
        $machineTencentPlay = MachineTencentPlay::query()->first();
        try {
            $cred = new Credential($machineTencentPlay->api_appid, $machineTencentPlay->api_key);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("live.tencentcloudapi.com");
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new LiveClient($cred, "", $clientProfile);
            $req = new ResumeLiveStreamRequest();
            $params = [
                'AppName' => 'live',
                'DomainName' => $machineTencentPlay->push_domain,
                'StreamName' => $machineCode
            ];
            $req->fromJsonString(json_encode($params));
            $client->ResumeLiveStream($req);
        } catch (TencentCloudSDKException $e) {
            throw new \Exception($e->getMessage());
        }

        return true;
    }

    /**
     * 禁用流推流
     * @param $machineCode
     * @return int
     * @throws \Exception
     */
    public function forbidLiveStream($machineCode): int
    {
        /** @var MachineTencentPlay $machineTencentPlay */
        $machineTencentPlay = MachineTencentPlay::query()->first();
        try {
            $cred = new Credential($machineTencentPlay->api_appid, $machineTencentPlay->api_key);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("live.tencentcloudapi.com");
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new LiveClient($cred, "", $clientProfile);
            $req = new ForbidLiveStreamRequest();
            $params = [
                'AppName' => 'live',
                'DomainName' => $machineTencentPlay->push_domain,
                'StreamName' => $machineCode
            ];
            $req->fromJsonString(json_encode($params));
            $client->ForbidLiveStream($req);
        } catch (TencentCloudSDKException $e) {
            throw new \Exception($e->getMessage());
        }

        return true;
    }

    /**
     * 获取腾讯流观看5分钟人数
     * @param $apiAppid
     * @param $apiKey
     * @param $streamName
     * @return int
     */
    public function getTencentViewers2($apiAppid, $apiKey, $streamName): int
    {
        try {
            $cred = new Credential($apiAppid, $apiKey);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("live.tencentcloudapi.com");
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new LiveClient($cred, "", $clientProfile);
            $req = new DescribeStreamPlayInfoListRequest();
            $params = [
                'StreamName' => $streamName,
                'StartTime' => date('Y-m-d H:i:s', strtotime('-3 minute')),
                'EndTime' => date('Y-m-d H:i:s', strtotime('-1 minute')),
                'ServiceName' => 'LEB',
            ];
            $req->fromJsonString(json_encode($params));
            $resp = $client->DescribeStreamPlayInfoList($req)->DataInfoList;
        } catch (TencentCloudSDKException $e) {
            $this->log->error('getTencentViewers5', [$e->getMessage()]);
            return false;
        }
        $hasViewer = false;
        if (count($resp) == 1) {
            return true;
        }
        foreach ($resp as $item) {
            if ($item->Online > 0) {
                $hasViewer = true;
                break;
            }
        }

        return $hasViewer;
    }

    /**
     * 重设视讯流
     * @param MachineMediaPush $machineMediaPush
     * @return false|string
     */
    public function rebuildMedia(MachineMediaPush $machineMediaPush): bool|string
    {
        Db::beginTransaction();
        try {
            $pushList = [];
            $insertData = [];
            /** @var MachineTencentPlay $machineTencentPlay */
            $machineTencentPlay = MachineTencentPlay::query()->where('id',
                $machineMediaPush->machine_tencent_play_id)->first();
            $pushData = getPushUrl($machineMediaPush->machine->code, $machineTencentPlay->push_domain,
                $machineTencentPlay->push_key);
            $pushList[] = [
                'type' => 'generic',
                'rtmpUrl' => $pushData['rtmp_url'],
                'endpointServiceId' => $pushData['endpoint_service_id'],
            ];
            $insertData[] = [
                'machine_id' => $machineMediaPush->machine_id,
                'media_id' => $machineMediaPush->media->id,
                'endpoint_service_id' => $pushData['endpoint_service_id'],
                'expiration_date' => $pushData['expiration_date'],
                'machine_code' => $machineMediaPush->media->machine->code,
                'rtmp_url' => $pushData['rtmp_url'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'machine_tencent_play_id' => $machineTencentPlay->id,
            ];
            $result = $this->resetMachineStream($machineMediaPush->media->machine->type,
                $machineMediaPush->media->stream_name, $machineMediaPush->media->machine->code,
                $machineMediaPush->media->media_ip, '', '', 'WebRTCAppEE', '', $pushList);
            if ($result && $result['success']) {
                $machineMediaPush->media->stream_name = $result['dataId'];
            } else {
                $machineMediaPush->media->stream_name = -1;
            }

            $machineMediaPush->media->push();
            MachineMediaPush::query()->where('id', $machineMediaPush->id)->delete();
            if (!empty($insertData)) {
                MachineMediaPush::query()->insert($insertData);
            }
            Db::commit();
        } catch (Exception) {
            Db::rollback();
            return false;
        }

        return $machineMediaPush->media->machine->code . $pushData['endpoint_service_id'];
    }

    /**
     * @param int $type 机台类型
     * @param string $streamName 串流码
     * @param string $code 机台编号
     * @param string $mediaIp 视频ip
     * @param string $oldPushIp 推流ip
     * @param string $newPushIp 新推流ip
     * @param string $oldMediaApp 媒体服务app
     * @param string $newMediaApp 新媒体服务app
     * @param array $pushList
     * @return mixed
     * @throws Exception
     */
    public function resetMachineStream(
        int    $type,
        string $streamName = '',
        string $code = '',
        string $mediaIp = '',
        string $oldPushIp = '',
        string $newPushIp = '',
        string $oldMediaApp = 'WebRTCAppEE',
        string $newMediaApp = '',
        array  $pushList = []
    ): mixed
    {
        if (!empty($streamName)) {
            $this->deleteMachineStream($streamName);
        }
        if (empty($code)) {
            throw new Exception(trans('media.media_name_must', [], 'message'));
        }
        if (empty($mediaIp)) {
            throw new Exception(trans('media.media_url_must', [], 'message'));
        }
        if (!empty($newPushIp) && $oldPushIp != $newPushIp) {
            $this->domain = $newPushIp;
        }
        if (!empty($newMediaApp) && $oldMediaApp != $newMediaApp) {
            $this->mediaApp = $newMediaApp;
        }
        return $this->createMachineStream($code, $mediaIp, $type, $pushList);
    }

    /**
     * 删除流
     * @param $streamName
     * @param string $domain
     * @param string $oldMediaApp
     * @return bool
     */
    public function deleteMachineStream($streamName, string $domain = '', string $oldMediaApp = ''): bool
    {
        $domain = !empty($domain) ? $domain : $this->domain;
        $mediaApp = !empty($oldMediaApp) ? $oldMediaApp : $this->mediaApp;
        try {
            $response = Http::timeout(5)->asJson()->delete($domain . '/' . $mediaApp . '/rest/v2/broadcasts/' . $streamName);
        } catch (\Exception) {
            return false;
        }
        $this->log->info('deleteMachineStream',
            [$response, $domain . '/' . $mediaApp . '/rest/v2/broadcasts/' . $streamName]);
        if (!empty($response) && $response->status() == 200) {
            $response = json_decode($response, true);
            $this->log->info('deleteMachineStream',
                [$response, $domain . '/' . $mediaApp . '/rest/v2/broadcasts/' . $streamName]);
            if (empty($response['success'])) {
                return false;
            }
        } else {
            return false;
        }
        return true;
    }

    /**
     * 创建视频流
     * @param string $name 名称
     * @param string $stream_url 流ip+端口
     * @param int $type 机台类型
     * @param array $pushList
     * @return mixed
     * @throws Exception
     */
    public function createMachineStream(string $name, string $stream_url, int $type, array $pushList = []): mixed
    {
        if (strpos($stream_url, 'rtsp') !== false) {
            throw new Exception(trans('media.media_stream_url_error', [], 'message'));
        }

        $stream_url = $type == GameType::TYPE_FISH ? str_replace('{ip}', $stream_url,
            $this->fish_stream_url) : str_replace('stream_url', $stream_url, $this->stream_url);
        try {
            $response = Http::timeout(5)->asJson()->post($this->domain . '/' . $this->mediaApp . '/rest/v2/broadcasts/create?autoStart=true',
                [
                    'hlsViewerCount' => 0,
                    'mp4Enabled' => 0,
                    'name' => $name,
                    'playListItemList' => [],
                    'rtmpViewerCount' => 0,
                    'streamUrl' => $stream_url,
                    'type' => 'streamSource',
                    'webRTCViewerCount' => 0,
                    'endPointList' => $pushList
                ]);
        } catch (\Exception $e) {
            $this->log->info('createMachineStream', [$e->getMessage()]);
            throw new Exception(trans('media.media_request_error', [], 'message'));
        }

        $this->log->info('createMachineStream', [$response]);
        if (!empty($response) && $response->status() == 200) {
            $response = json_decode($response, true);
            if (empty($response['success'])) {
                throw new Exception(trans('media.media_stream_pull_error', [], 'message'));
            }
        } else {
            throw new Exception(trans('media.media_request_error', [], 'message'));
        }

        return $response;
    }

    /**
     * 恢复流推流
     * @param $machineCode
     * @return string
     * @throws \Exception
     */
    public function describeLiveStreamState($machineCode): string
    {
        /** @var MachineTencentPlay $machineTencentPlay */
        $machineTencentPlay = MachineTencentPlay::query()->first();
        try {
            $cred = new Credential($machineTencentPlay->api_appid, $machineTencentPlay->api_key);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("live.tencentcloudapi.com");
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new LiveClient($cred, "", $clientProfile);
            $req = new DescribeLiveStreamStateRequest();
            $params = [
                'AppName' => 'live',
                'DomainName' => $machineTencentPlay->push_domain,
                'StreamName' => $machineCode
            ];
            $req->fromJsonString(json_encode($params));
            $req = $client->DescribeLiveStreamState($req);
        } catch (TencentCloudSDKException $e) {
            throw new \Exception($e->getMessage());
        }

        return $req->StreamState;
    }

    /**
     * 获取腾讯流观看5分钟人数
     * @param MachineMedia $media
     * @return array
     */
    public function getTencentViewersTest(MachineMedia $media): array
    {
        /** @var MachineMediaPush $machineMediaPush */
        $machineMediaPush = $media->machineMediaPush->first();
        try {
            $cred = new Credential($machineMediaPush->machineTencentPlay->api_appid,
                $machineMediaPush->machineTencentPlay->api_key);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("live.tencentcloudapi.com");
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new LiveClient($cred, "", $clientProfile);
            $req = new DescribeStreamPlayInfoListRequest();
            $params = [
                'StreamName' => $machineMediaPush->machine_code . '_' . $machineMediaPush->endpoint_service_id,
                'StartTime' => date('Y-m-d H:i:s', strtotime('-6 minute')),
                'EndTime' => date('Y-m-d H:i:s', strtotime('-1 minute')),
                'ServiceName' => 'LEB',
            ];
            $req->fromJsonString(json_encode($params));
            $resp = $client->DescribeStreamPlayInfoList($req)->DataInfoList;
            $this->log->info('getTencentViewers5', [$resp, $machineMediaPush->machine_code, $params]);
        } catch (TencentCloudSDKException $e) {
            $this->log->error('getTencentViewers5', [$e->getMessage()]);
            return [];
        }

        return $resp;
    }

    /**
     * 获取流信息
     * @param $streamName
     * @return mixed
     * @throws Exception
     */
    public function getBroadcasts($streamName): mixed
    {
        try {
            $response = Http::timeout(5)->get($this->domain . '/' . $this->mediaApp . '/rest/v2/broadcasts/' . $streamName);
        } catch (\Exception) {
            throw new Exception('请求视讯主机失败');
        }
        if (empty($response) || $response->status() != 200) {
            throw new Exception('获取流信息是吧');
        }

        return json_decode($response->body(), true);
    }
}