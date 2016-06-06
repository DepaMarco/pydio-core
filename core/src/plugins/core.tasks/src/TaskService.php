<?php
/*
 * Copyright 2007-2015 Abstrium <contact (at) pydio.com>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */
namespace Pydio\Tasks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pydio\Access\Core\Model\AJXP_Node;
use Pydio\Access\Core\Model\NodesDiff;
use Pydio\Access\Core\Model\Repository;
use Pydio\Conf\Core\AbstractAjxpUser;
use Pydio\Core\Controller\Controller;
use Pydio\Core\Exception\PydioException;
use Pydio\Core\Model\Context;
use Pydio\Core\Model\ContextInterface;
use Pydio\Core\Services\ConfService;
use Pydio\Core\Utils\Utils;
use Pydio\Log\Core\AJXP_Logger;

defined('AJXP_EXEC') or die('Access not allowed');

class TaskService implements ITasksProvider
{
    /**
     * @var ITasksProvider
     */
    private $realProvider;

    /**
     * @var TaskService
     */
    private static $instance;

    public function setProvider(ITasksProvider $provider){
        $this->realProvider = $provider;
    }

    /**
     * @return TaskService
     */
    public static function getInstance(){
        if(!isSet(self::$instance)){
            self::$instance = new TaskService();
        }
        return self::$instance;
    }

    /**
     * @param Task $task
     * @param ServerRequestInterface|null $request
     * @param ResponseInterface|null $response
     * @throws \Pydio\Core\Exception\ActionNotFoundException
     * @throws \Pydio\Core\Exception\AuthRequiredException
     * @return ResponseInterface|null
     */
    public function enqueueTask(Task $task, ServerRequestInterface $request = null, ResponseInterface $response = null){
        
        $workers = ConfService::getCoreConf("MQ_USE_WORKERS", "mq");
        if($workers && !$task->getSchedule()->shouldRunNow()){
            AJXP_Logger::getInstance()->logInfo("TaskService", "Enqueuing Task ".$task->getId());
            $msg = ["pending_task" => $task->getId()];
            Controller::applyHook("msg.task", [$task->getContext(), $msg]);
            return $response;
        }

        if(ConfService::backgroundActionsSupported() && !ConfService::currentContextIsCommandLine()) {

            Controller::applyTaskInBackground($task);
            return $response;

        }else{
            $params = $task->getParameters();
            $action = $task->getAction();
            $id = $task->getId();
            if(empty($request)){
                $ctx = Context::fromGlobalServices();
            }else{
                $ctx = $request->getAttribute("ctx");
            }
            $request = Controller::executableRequest($ctx, $action, $params)->withAttribute("pydio-task-id", $id);
            return Controller::run($request);
        }

    }

    protected function publishTaskUpdate(Task $task){

        $json = Utils::xmlEntities(json_encode($task));
        if(count($task->nodes)){
            $nodesDiff = new NodesDiff();
            foreach($task->nodes as $url){
                $n = new AJXP_Node($url);
                $n->loadNodeInfo(true, false, "all");
                Controller::applyHook("node.meta_change", array(&$n));
                $nodesDiff->update($n);
            }
        }
        $xmlString = "";
        if(isSet($nodesDiff)){
            $xmlString = $nodesDiff->toXML();
        }
        Controller::applyHook("msg.instant", array($task->getContext(), "<task id='".$task->getId()."' data=\"".$json."\"/>".$xmlString, $task->getUserId()));

    }

    public static function actionAsTask(ContextInterface $ctx, $actionName, $parameters, $nodePathes = [], $flags = 0){

        $userId = $ctx->hasUser() ? $ctx->getUser()->getId() : "shared";
        $repoId = $ctx->getRepositoryId();
        
        $task = new Task();
        $task->setLabel("Launching action ".$actionName);
        $task->setId(Utils::createGUID());
        $task->setUserId($userId);
        $task->setWsId($repoId);
        $task->setStatus(Task::STATUS_PENDING);
        $task->setStatusMessage("Starting...");
        $task->setAction($actionName);
        $task->setParameters($parameters);
        $task->setFlags($flags);
        if(count($nodePathes)){
            array_map(function ($node) use ($task){
                $task->attachToNode($node);
            }, $nodePathes);
        }
        TaskService::getInstance()->createTask($task, Schedule::scheduleNow());

        return $task;

    }

    /**
     * @param string $taskId
     * @param integer $status
     * @param string $message
     * @param bool|null $stoppable
     * @param integer|null $progress
     * @return Task
     * @throws PydioException
     */
    public function updateTaskStatus($taskId, $status, $message, $stoppable = null, $progress = null){
        $t = self::getTaskById($taskId);
        if(empty($t)){
            throw new PydioException("Cannot find task with this id");
        }
        $t->setStatus($status);
        $t->setStatusMessage($message);
        if($stoppable !== null){
            $f = $t->isResumable() ? Task::FLAG_RESUMABLE : 0;
            $f = $t->hasProgress() ? $f | Task::FLAG_HAS_PROGRESS : $f;
            $f = $stoppable ? $f | Task::FLAG_STOPPABLE : $f;
            $t->setFlags($f);
        }
        if($progress !== null){
            $t->setProgress($progress);
        }
        return self::updateTask($t);
    }

    /**
     * @param Task $task
     * @param Schedule $when
     * @return Task
     */
    public function createTask(Task $task, Schedule $when)
    {
        $res = $this->realProvider->createTask($task, $when);
        $this->publishTaskUpdate($task);
        return $res;
    }

    /**
     * @param string $taskId
     * @return Task
     */
    public function getTaskById($taskId)
    {
        return $this->realProvider->getTaskById($taskId);
    }

    /**
     * @param Task $task
     * @return Task
     */
    public function updateTask(Task $task)
    {
        $res = $this->realProvider->updateTask($task);
        $this->publishTaskUpdate($task);
        return $res;
    }

    /**
     * @param string $taskId
     * @return bool
     */
    public function deleteTask($taskId)
    {
        return $this->realProvider->deleteTask($taskId);
    }

    /**
     * @return Task[]
     */
    public function getPendingTasks()
    {
        return $this->realProvider->getPendingTasks();
    }

    /**
     * @inheritdoc
     */
    public function getCurrentRunningTasks($user, $repository)
    {
        return $this->realProvider->getCurrentRunningTasks($user, $repository);
    }

    /**
     * @param AJXP_Node $node
     * @return Task[]
     */
    public function getActiveTasksForNode(AJXP_Node $node)
    {
        return $this->realProvider->getActiveTasksForNode($node);
    }

    /**
     * @param AbstractAjxpUser $user
     * @param Repository $repository
     * @param int $status
     * @return Task[]
     */
    public function getTasks($user = null, $repository = null, $status = -1)
    {
        return $this->realProvider->getTasks($user, $repository, $status);
    }
}