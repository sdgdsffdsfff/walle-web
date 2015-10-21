<?php
/* *****************************************************************
 * @Author: wushuiyong
 * @Created Time : 五  7/31 22:21:23 2015
 *
 * @File Name: command/Folder.php
 * @Description:
 * *****************************************************************/
namespace app\components;

use app\models\Project;

class Folder extends Command {


    /**
     * 初始化宿主机部署工作空间
     *
     * @return bool
     */
    public function initLocalWorkspace($version) {
        $cmd[] = 'mkdir -p ' . Project::getDeployWorkspace($version);
        if ($this->config->repo_type == Project::REPO_SVN) {
            $cmd[] = sprintf('mkdir -p %s-svn', rtrim(Project::getDeployWorkspace($version), '/'));
        }
        $command = join(' && ', $cmd);
        return $this->runLocalCommand($command);
    }

    /**
     * 目标机器的版本库初始化
     * 这里会有点特殊化：
     * 1.git只需要生成版本目录即可
     * 2.svn还需要把线上版本复制到1生成的版本目录中，做增量发布
     *
     * @author wushuiyong
     * @param $log
     * @return bool
     */
    public function initRemoteVersion($version) {
        $cmd[] = sprintf('mkdir -p %s', Project::getReleaseVersionDir($version));
        if ($this->config->repo_type == Project::REPO_SVN) {
            $cmd[] = sprintf('test -d %s && cp -rf %s/* %s/ || echo 1', // 无论如何总得要$?执行成功
                $this->config->release_to, $this->config->release_to, Project::getReleaseVersionDir($version));
        }
        $command = join(' && ', $cmd);

        return $this->runRemoteCommand($command);
    }

    /**
     * rsync 同步文件
     *
     * @param $remoteHost 远程host，格式：host 、host:port
     * @return bool
     */
    public function syncFiles($remoteHost, $version) {
        $excludes = GlobalHelper::str2arr($this->getConfig()->excludes);

        $command = sprintf('rsync -avz --rsh="ssh -p %s" %s %s %s%s:%s',
            $this->getHostPort($remoteHost),
            $this->excludes($excludes),
            rtrim(Project::getDeployWorkspace($version), '/') . '/',
            $this->getConfig()->release_user . '@',
            $this->getHostName($remoteHost),
            Project::getReleaseVersionDir($version));

        return $this->runLocalCommand($command);
    }

    /**
     * 打软链
     *
     * @param null $version
     * @return bool
     */
    public function getLinkCommand($version) {
        $user = $this->getConfig()->release_user;
        $project = Project::getGitProjectName($this->getConfig()->repo_url);
        $currentTmp = sprintf('%s/%s/current-%s.tmp', rtrim($this->getConfig()->release_library, '/'), $project, $project);
        // 遇到回滚，则使用回滚的版本version
        $linkFrom = Project::getReleaseVersionDir($version);
        $cmd[] = sprintf('ln -sfn %s %s', $linkFrom, $currentTmp);
        $cmd[] = sprintf('chown -h %s %s', $user, $currentTmp);
        $cmd[] = sprintf('mv -fT %s %s', $currentTmp, $this->getConfig()->release_to);

        return join(' && ', $cmd);
    }

    /**
     * 获取文件的MD5
     *
     * @param $file
     * @return bool
     */
    public function getFileMd5($file) {
        $cmd[] = "test -f /usr/bin/md5sum && md5sum {$file}";
        $command = join(' && ', $cmd);

        return $this->runRemoteCommand($command);
    }

    /**
     * rsync时，要排除的文件
     *
     * @param array $excludes
     * @return string
     */
    protected function excludes($excludes) {
        $excludesRsync = '';
        foreach ($excludes as $exclude) {
            $excludesRsync .= sprintf(" --exclude=%s", escapeshellarg(trim($exclude)));
        }


        return trim($excludesRsync);
    }

    /**
     * 收尾做处理工作，如清理本地的部署空间
     *
     * @param $version
     * @return bool|int
     */
    public function cleanUp($version) {
        $cmd[] = "rm -rf " . Project::getDeployWorkspace($version);
        if ($this->config->repo_type == Project::REPO_SVN) {
            $cmd[] = sprintf('rm -rf %s-svn', rtrim(Project::getDeployWorkspace($version), '/'));
        }
        $command = join(' && ', $cmd);
        return $this->runLocalCommand($command);
    }
}

