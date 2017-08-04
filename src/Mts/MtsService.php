<?php
namespace Mts;

include_once 'Core/Config.php';
use OSS\OssClient;
use Mts\Request\V20140618 as Mts;
use Mts\Core as Core;

/**
 * Media Transcoding Service(Mts) 的客户端类，封装了用户通过Mts API对储存在OSS上的媒体文件的各种操作，
 * 用户通过MtsService实例可以进行Snapshot，Transcode，Template, AnalysisJob, Pipeline等操作，具体
 * 的接口规则可以参考官方Mts API文档
 * Class MtsService
 * @package Mts
 */

class MtsService
{
    /**
     * Mts 服务中心（华东1，华东2，华北1等），例如：cn-hangzhou
     * @var
     */
    public $mts_region;


    /**
     * OSS存储中心（华东1，华东2，华北1等），例如：oss-cn-hangzhou
     * @var
     */
    public $oss_region;


    /**
     * 例如：http://mts.cn-shanghai.aliyuncs.com
     * @var
     */
    public $mts_endpoint;


    /**
     * 例如：oss-cn-shanghai.aliyuncs.com
     * @var
     */
    public $oss_endpoint;


    /**
     * 阿里云颁发给用户的访问服务所用的密钥ID。
     * @var
     */
    public $access_key_id;


    /**
     * 阿里云颁发给用户的访问服务所用的密钥
     * @var
     */
    public $access_key_secret;


    /**
     * 转码所用管道id
     * @var
     */
    public $pipeline_id;


    /**
     * 转码模板id
     * @var
     */
    public $transcode_template_id;


    /**
     * 水印模板id
     * @var
     */
    public $watermark_template_id;


    /**
     * 输入媒体OSS Bucket
     * @var
     */
    public $input_bucket;


    /**
     * 输出媒体OSS Bucket
     * @var
     */
    public $output_bucket;


    /**
     * @var
     */
    public $profile;


    /**
     * @var
     */
    public $client;


    public function __construct()
    {
        $this->mts_region = env('ALIYUN_MTS_REGION', 'cn-shanghai');
        $this->oss_region = env('ALIYUN_OSS_REGION','oss-cn-shanghai');
        $this->mts_endpoint = env('ALIYUN_MTS_ENDPOINT', 'http://mts.cn-shanghai.aliyuncs.com');
        $this->oss_endpoint = env('ALIYUN_OSS_ENDPOINT','oss-cn-shanghai.aliyuncs.com');
        $this->access_key_id = env('ALIYUN_OSS_ACCESS_ID');
        $this->access_key_secret = env('ALIYUN_OSS_ACCESS_KEY');
        $this->transcode_template_id = env('ALIYUN_MTS_TRANSCODE_TEMPLATE_ID', '');
        $this->pipeline_id = env('ALIYUN_MTS_PIPELINE_ID', '3500393f3f5b4a9c99ee078d550eed90');
        $this->watermark_template_id = env('ALIYUN_MTS_WATERMARK_TEMPLATE_ID', '');
        $this->input_bucket = env('ALIYUN_OSS_BUCKET', 'example-bucket');
        $this->output_bucket = env('ALIYUN_OSS_BUCKET', 'example-bucket');

        //初始化Client
        $this->profile = Core\DefaultProfile::getProfile($this->mts_region, $this->access_key_id, $this->access_key_secret);
        $this->client = new Core\DefaultAcsClient($this->profile);
    }


    /**
     * 上传文件至OSS
     * @param $filename
     * @param $bucket
     * @param $obj
     * @return array
     */
    public function uploadFile($filename, $bucket, $obj)
    {
        $ossClient = new OssClient($this->access_key_id,
            $this->access_key_secret,
            $this->oss_endpoint,
            false);
        $ossClient->uploadFile($bucket, $obj, $filename);

        return array(
            'Location' => $this->oss_region,
            'Bucket' => $bucket,
            'Object' => urlencode($obj)
        );
    }


    /**
     * 截图工作流程
     * @param $input_file
     */
    public function snapshotJobFlow($input_file)
    {
        $snapshot_job = $this->submitSnapshotJob($input_file);

        print 'Snapshot success, the target file url is http://' .
            $snapshot_job->{'SnapshotConfig'}->{'OutputFile'}->{'Bucket'} . '.' .
            $snapshot_job->{'SnapshotConfig'}->{'OutputFile'}->{'Location'} . '.aliyuncs.com/' .
            urldecode($snapshot_job->{'SnapshotConfig'}->{'OutputFile'}->{'Object'}) . "\n";
    }


    /**
     * 视频截图
     * 截图作业由输入文件及截图配置构成，得到输入文件按截图配置截取的图片。
     * @param $input_file
     * @param $output_img
     * @return \SimpleXMLElement[]
     */
    public function submitSnapshotJob($input_file , $output_img)
    {
        $obj = $output_img;
        $inputFile = [
            'Location' => $this->oss_region,
            'Bucket' => $this->input_bucket,
            'Object' => urlencode($input_file)
        ];
        $snapshot_output = array(
            'Location' => $this->oss_region,
            'Bucket' => $this->input_bucket,
            'Object' => urlencode($obj)
        );
        $snapshot_config = array(
            'OutputFile' => $snapshot_output,
            //截取视频第5秒的图片
            'Time' => 5000
        );

        $request = new Mts\SubmitSnapshotJobRequest();
        $request->setAcceptFormat('JSON');
        $request->setInput(json_encode($inputFile));
        $request->setSnapshotConfig(json_encode($snapshot_config));

        $response = $this->client->getAcsResponse($request);
        return $response->{'SnapshotJob'};
    }


    /**
     * 转码工作流程
     * @param $input_file
     * @param $watermark_file
     */
    public function transcodeJobFlow($input_file, $watermark_file)
    {
        $this->systemTemplateJobFlow($input_file, $watermark_file);

        $this->userCustomTemplateJobFlow($input_file, $watermark_file);
    }


    /**
     * 系统预置模板转码流程
     * @param $input_file
     * @param $watermark_file
     */
    public function systemTemplateJobFlow($input_file, $watermark_file)
    {
        $analysis_id = $this->submitAnalysisJob($input_file, $this->pipeline_id);
        $analysis_job = $this->waitAnalysisJobComplete($analysis_id);
        $template_ids = $this->getSupportTemplateIds($analysis_job);

        # 可能会有多个系统模板，这里采用推荐的第一个系统模板进行转码
        $transcode_job_id = $this->submitTranscodeJob($input_file, $watermark_file, $template_ids[0]);
        $transcode_job = $this->waitTranscodeJobComplete($transcode_job_id);

        print 'Transcode success, the target file url is http://' .
            $transcode_job->{'Output'}->{'OutputFile'}->{'Bucket'} . '.' .
            $transcode_job->{'Output'}->{'OutputFile'}->{'Location'} . '.aliyuncs.com/' .
            urldecode($transcode_job->{'Output'}->{'OutputFile'}->{'Object'}) . "\n";
    }


    /**
     * 预置模板分析作业
     * 预置模板分析作业由输入文件及分析配置构成，分析得到可用的预置模板。
     * @param $input_file
     * @return mixed
     */
    public function submitAnalysisJob($input_file)
    {
        $request = new Mts\SubmitAnalysisJobRequest();
        $request->setAcceptFormat('JSON');
        $inputFile = [
            'Bucket' => $this->input_bucket,
            'Location' => $this->oss_region,
            'Object' => $input_file
        ];
        $request->setInput(json_encode($inputFile));
        $request->setPriority(5);
        $request->setUserData('SubmitAnalysisJob userData');
        $request->setPipelineId($this->pipeline_id);
        $response = $this->client->getAcsResponse($request);

        return $response->{'AnalysisJob'}->{'Id'};
    }


    /**
     * 返回分析作业分析结果
     * @param $analysis_id
     * @return null
     */
    public function waitAnalysisJobComplete($analysis_id)
    {
        while (true)
        {
            $request = new Mts\QueryAnalysisJobListRequest();
            $request->setAcceptFormat('JSON');
            $request->setAnalysisJobIds($analysis_id);

            $response = $this->client->getAcsResponse($request);
            $state = $response->{'AnalysisJobList'}->{'AnalysisJob'}[0]->{'State'};
            if ($state != 'Success')
            {
                if ($state == 'Submitted' or $state == 'Analyzing')
                {
                    sleep(5);
                } elseif ($state == 'Fail') {
                    print 'AnalysisJob is failed!';
                    return null;
                }
            } else {
                return $response->{'AnalysisJobList'}->{'AnalysisJob'}[0];
            }
        }
        return null;
    }


    /**
     * 返回可供选择的预置静态模板id
     * 通过分析作业，对input_file动态推荐合适的预制模板
     * @param $analysis_job
     * @return array
     */
    public function getSupportTemplateIds($analysis_job)
    {
        $result = array();

        foreach ($analysis_job->{'TemplateList'}->{'Template'} as $template)
        {
            $result[] = $template->{'Id'};
        }

        return $result;
    }


    /**
     * 转码作业
     * 转码作业，一个转码作业由一路输入及一路输出构成，作业会被加入到管道中，管道中的作业会被调度引擎调度到转码系统进行转码。
     * @param $input_file
     * @param $template_id
     * @param $output_file
     * @return mixed
     */
    public function submitTranscodeJob($input_file, $template_id , $output_file)
    {
        //水印设置
      //  $watermark_config = array();
        //   $watermark_config[] = array(
        //       'InputFile' => json_encode($watermark_file),
        //       'WaterMarkTemplateId' => $this->watermark_template_id
        //   );

        $inputFile = [
            'Bucket' => $this->input_bucket,
            'Location' => $this->oss_region,
            'Object' => $input_file
        ];

        $obj = $output_file;
        $outputs = array();
        $outputs[] = array(
            'OutputObject'=> urlencode($obj),
            'TemplateId' => $template_id,
            //     'WaterMarks' => $watermark_config
        );

        $request = new Mts\SubmitJobsRequest();
        $request->setAcceptFormat('JSON');
        $request->setInput(json_encode($inputFile));
        $request->setOutputBucket($this->output_bucket);
        $request->setOutputLocation($this->oss_region);
        $request->setOutputs(json_encode($outputs));
        $request->setPipelineId($this->pipeline_id);

        $response = $this->client->getAcsResponse($request);
        return $response->{'JobResultList'}->{'JobResult'}[0]->{'Job'}->{'JobId'};
    }


    /**
     * 返回转码作业结果
     * @param $transcode_job_id
     * @return null
     */
    public function waitTranscodeJobComplete($transcode_job_id)
    {
        while (true)
        {
            $request = new Mts\QueryJobListRequest();
            $request->setAcceptFormat('JSON');
            $request->setJobIds($transcode_job_id);

            $response = $this->client->getAcsResponse($request);
            $state = $response->{'JobList'}->{'Job'}[0]->{'State'};
            if ($state != 'TranscodeSuccess')
            {
                if ($state == 'Submitted' or $state == 'Transcoding')
                {
                    sleep(5);
                } elseif ($state == 'TranscodeFail') {
                    print 'Transcode is failed!';
                    return null;
                }
            } else {
                return $response->{'JobList'}->{'Job'}[0];
            }
        }
        return null;
    }


    /**
     * 用户自定义模板转码流程
     * @param $input_file
     * @param $watermark_file
     */
    public function userCustomTemplateJobFlow($input_file, $watermark_file)
    {
        $transcode_job_id = $this->submitTranscodeJob($input_file, $watermark_file, $this->transcode_template_id);
        $transcode_job = $this->waitTranscodeJobComplete($transcode_job_id);

        print 'Transcode success, the target file url is http://' .
            $transcode_job->{'Output'}->{'OutputFile'}->{'Bucket'} . '.' .
            $transcode_job->{'Output'}->{'OutputFile'}->{'Location'} . '.aliyuncs.com/' .
            urldecode($transcode_job->{'Output'}->{'OutputFile'}->{'Object'}) . "\n";
    }

     /**
     * 提交视频信息Job
     * @param $input_file
     * @return mixed
     */
    public function submitMediaInfoJob($input_file)
    {
        $request = new Mts\SubmitMediaInfoJobRequest();
        $request->setAcceptFormat('JSON');
        $inputFile = [
            'Bucket' => $this->input_bucket,
            'Location' => $this->oss_region,
            'Object' => $input_file
        ];
        $request->setInput(json_encode($inputFile));
        $request->setUserData('SubmitMediaInfoJob userData');
        $request->setPipelineId($this->pipeline_id);
        $response = $this->client->getAcsResponse($request);
        return $response->{'MediaInfoJob'};
    }
}
