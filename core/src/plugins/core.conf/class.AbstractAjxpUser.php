<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
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
namespace Pydio\Conf\Core;

use Pydio\Core\Model\RepositoryInterface;
use Pydio\Core\Model\UserInterface;
use Pydio\Core\Services\ConfService;
use Pydio\Core\Services\RepositoryService;
use Pydio\Core\Services\RolesService;
use Pydio\Core\Services\UsersService;

defined('AJXP_EXEC') or die( 'Access not allowed');

abstract class AbstractAjxpUser implements UserInterface
{
    /**
     * @var string
     */
    public $id;
    public $hasAdmin = false;
    public $rights;
    /**
     * @var AJXP_Role[]
     */
    public $roles;
    public $prefs;
    public $bookmarks;
    public $version;
    /**
     * @var string
     */
    public $parentUser;
    /**
     * @var bool
     */
    protected $hidden;

    public function setHidden($hidden)
    {
        $this->hidden = $hidden;
    }

    public function isHidden()
    {
        return $this->hidden;
    }

    public $groupPath = "/";
    /**
     * @var AJXP_Role
     */
    public $mergedRole;

    /**
     * @var AJXP_Role
     */
    public $parentRole;

    /**
     * @var AJXP_Role Accessible for update
     */
    public $personalRole;

    /**
     * Conf Storage implementation
     *
     * @var AbstractConfDriver
     */
    public $storage;

    public function __construct($id, $storage=null)
    {
        $this->id = $id;
        if ($storage == null) {
            $storage = ConfService::getConfStorageImpl();
        }
        $this->storage = $storage;
        $this->load();
    }
    
    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id ;
    }

    public function storageExists()
    {
    }

    public function addRole($roleObject)
    {
        if (isSet($this->roles[$roleObject->getId()])) {
            // NOTHING SPECIAL TO DO !
            return;
        }
        if(!isSet($this->rights["ajxp.roles"])) $this->rights["ajxp.roles"] = array();
        $this->rights["ajxp.roles"][$roleObject->getId()] = true;
        if(!isSet($this->rights["ajxp.roles.order"])){
            $this->rights["ajxp.roles.order"] = array();
        }
        $this->rights["ajxp.roles.order"][$roleObject->getId()] = count($this->rights["ajxp.roles"]);
        if($roleObject->alwaysOverrides()){
            if(!isSet($this->rights["ajxp.roles.sticky"])){
                $this->rights["ajxp.roles.sticky"] = array();
            }
            $this->rights["ajxp.roles.sticky"][$roleObject->getId()] = true;
        }
        uksort($this->rights["ajxp.roles"], array($this, "orderRoles"));
        $this->roles[$roleObject->getId()] = $roleObject;
        $this->recomputeMergedRole();
    }

    public function removeRole($roleId)
    {
        if (isSet($this->rights["ajxp.roles"]) && isSet($this->rights["ajxp.roles"][$roleId])) {
            unset($this->rights["ajxp.roles"][$roleId]);
            if(isSet($this->roles[$roleId])) unset($this->roles[$roleId]);
            if(isSet($this->rights["ajxp.roles.sticky"]) && isSet($this->rights["ajxp.roles.sticky"][$roleId])){
                unset($this->rights["ajxp.roles.sticky"][$roleId]);
            }
            if(isset($this->rights["ajxp.roles.order"]) && isset($this->rights["ajxp.roles.order"][$roleId])){
                $previousPos = $this->rights["ajxp.roles.order"][$roleId];
                $ordered = array_flip($this->rights["ajxp.roles.order"]);
                ksort($ordered);
                unset($ordered[$previousPos]);
                $reordered = array();
                $p = 0;
                foreach($ordered as $id) {
                    $reordered[$id] = $p;
                    $p++;
                }
                $this->rights["ajxp.roles.order"] = $reordered;
            }
            uksort($this->rights["ajxp.roles"], array($this, "orderRoles"));
        }
        $this->recomputeMergedRole();
    }

    public function updateRolesOrder($orderedRolesIds){
        // check content
        $saveRoleOrders = array();
        foreach($orderedRolesIds as $position => $rId){
            if(isSet($this->rights["ajxp.roles"][$rId])) $saveRoleOrders[$rId] = $position;
        }
        $this->rights["ajxp.roles.order"] = $saveRoleOrders;
    }

    public function getRoles()
    {
        if (isSet($this->rights["ajxp.roles"])) {
            uksort($this->rights["ajxp.roles"], array($this, "orderRoles"));
            return $this->rights["ajxp.roles"];
        } else {
            return array();
        }
    }

    public function getProfile()
    {
        if (isSet($this->rights["ajxp.profile"])) {
            return $this->rights["ajxp.profile"];
        }
        if($this->isAdmin()) return "admin";
        if($this->hasParent()) return "shared";
        if($this->getId() == "guest") return "guest";
        return "standard";
    }

    public function setProfile($profile)
    {
        $this->rights["ajxp.profile"] = $profile;
    }

    public function setLock($lockAction)
    {
        //$this->rights["ajxp.lock"] = $lockAction;
        $this->personalRole->setParameterValue('core.conf', 'USER_LOCK_ACTION', $lockAction);
        $this->recomputeMergedRole();
    }

    public function removeLock()
    {
        if(isSet($this->rights['ajxp.lock'])){
            $this->rights["ajxp.lock"] = false;
        }
        $this->personalRole->setParameterValue('core.conf', 'USER_LOCK_ACTION', AJXP_VALUE_CLEAR);
        $this->recomputeMergedRole();
    }

    public function getLock()
    {
        if(AJXP_SERVER_DEBUG && $this->isAdmin() && $this->getGroupPath() == "/") return false;
        if (!empty($this->rights["ajxp.lock"])) {
            return $this->rights["ajxp.lock"];
        }
        return $this->mergedRole->filterParameterValue('core.conf', 'USER_LOCK_ACTION', AJXP_REPO_SCOPE_ALL, false);
    }

    public function isAdmin()
    {
        return $this->hasAdmin;
    }

    public function setAdmin($boolean)
    {
        $this->hasAdmin = $boolean;
    }

    public function hasParent()
    {
        return isSet($this->parentUser);
    }

    public function setParent($user)
    {
        $this->parentUser = $user;
    }

    public function getParent()
    {
        return $this->parentUser;
    }

    public function canRead($repositoryId)
    {
        if($this->getLock() != false) return false;
        return $this->mergedRole->canRead($repositoryId);
    }

    public function canWrite($repositoryId)
    {
        if($this->getLock() != false) return false;
        return $this->mergedRole->canWrite($repositoryId);
    }

    /**
     * @param RepositoryInterface|string $idOrObject
     * @return bool
     */
    public function canAccessRepository($idOrObject){
        if($idOrObject instanceof RepositoryInterface){
            $repository = RepositoryService::getRepositoryById($idOrObject);
            if(empty($repository)) return false;
        }else{
            $repository = $idOrObject;
        }
        return RepositoryService::repositoryIsAccessible($repository, $this, false, true);
    }

    public function canSwitchTo($repositoryId)
    {
        $repositoryObject = RepositoryService::getRepositoryById($repositoryId);
        if($repositoryObject == null) return false;
        return RepositoryService::repositoryIsAccessible($repositoryObject, $this, false, true);
    }

    public function getPref($prefName)
    {
        if ($prefName == "lang") {
            // Migration path
            if (isSet($this->mergedRole)) {
                $l = $this->mergedRole->filterParameterValue("core.conf", "lang", AJXP_REPO_SCOPE_ALL, "");
                if($l != "") return $l;
            }
        }
        if(isSet($this->prefs[$prefName])) return $this->prefs[$prefName];
        return "";
    }

    public function setPref($prefName, $prefValue)
    {
        $this->prefs[$prefName] = $prefValue;
    }

    public function setArrayPref($prefName, $prefPath, $prefValue)
    {
        $data = $this->getPref($prefName);
        if(!is_array($data)){
            $data = array();
        }
        $data[$prefPath] = $prefValue;
        $this->setPref($prefName, $data);
    }

    public function getArrayPref($prefName, $prefPath)
    {
        $prefArray = $this->getPref($prefName);
        if(empty($prefArray) || !is_array($prefArray) || !isSet($prefArray[$prefPath])) return "";
        return $prefArray[$prefPath];
    }

    public function addBookmark($repositoryId, $path, $title)
    {
        if(!isSet($this->bookmarks)) $this->bookmarks = array();
        if(!isSet($this->bookmarks[$repositoryId])) $this->bookmarks[$repositoryId] = array();
        foreach ($this->bookmarks[$repositoryId] as $v) {
            $toCompare = "";
            if(is_string($v)) $toCompare = $v;
            else if(is_array($v)) $toCompare = $v["PATH"];
            if($toCompare == trim($path)) return ; // RETURN IF ALREADY HERE!
        }
        $this->bookmarks[$repositoryId][] = array("PATH"=>trim($path), "TITLE"=>$title);
    }

    public function removeBookmark($repositoryId, $path)
    {
        if(isSet($this->bookmarks)
            && isSet($this->bookmarks[$repositoryId])
            && is_array($this->bookmarks[$repositoryId]))
            {
                foreach ($this->bookmarks[$repositoryId] as $k => $v) {
                    $toCompare = "";
                    if(is_string($v)) $toCompare = $v;
                    else if(is_array($v)) $toCompare = $v["PATH"];
                    if($toCompare == trim($path)) unset($this->bookmarks[$repositoryId][$k]);
                }
            }
    }

    public function renameBookmark($repositoryId, $path, $title)
    {
        if(isSet($this->bookmarks)
            && isSet($this->bookmarks[$repositoryId])
            && is_array($this->bookmarks[$repositoryId]))
            {
                foreach ($this->bookmarks[$repositoryId] as $k => $v) {
                    $toCompare = "";
                    if(is_string($v)) $toCompare = $v;
                    else if(is_array($v)) $toCompare = $v["PATH"];
                    if ($toCompare == trim($path)) {
                         $this->bookmarks[$repositoryId][$k] = array("PATH"=>trim($path), "TITLE"=>$title);
                    }
                }
            }
    }

    public function getBookmarks($repositoryId)
    {
        if(isSet($this->bookmarks)
            && isSet($this->bookmarks[$repositoryId]))
            return $this->bookmarks[$repositoryId];
        return array();
    }

    abstract public function load();

    public function save($context = "superuser"){
        $this->_save($context);
        UsersService::updateUser($this);
    }

    /**
     * @param string $context
     * @return mixed
     */
    abstract protected function _save($context = "superuser");

    abstract public function getTemporaryData($key);

    abstract public function saveTemporaryData($key, $value);

    /**
     * @param String $groupPath
     * @param bool $update
     */
    public function setGroupPath($groupPath, $update = false)
    {
        if(strlen($groupPath) > 1) $groupPath = rtrim($groupPath, "/");
        $this->groupPath = $groupPath;
    }

    /**
     * @return null|string
     */
    public function getGroupPath()
    {
        if(!isSet($this->groupPath)) return null;
        return $this->groupPath;
    }


    /**
     * Automatically set the group to the current user base
     * @param $baseGroup
     * @return string
     */
    public function getRealGroupPath($baseGroup)
    {
        // make sure it starts with a slash.
        $baseGroup = "/".ltrim($baseGroup, "/");
        $groupPath = $this->getGroupPath();
        if(empty($groupPath)) $groupPath = "/";
        if ($groupPath != "/") {
            if($baseGroup == "/") return $groupPath;
            else return $groupPath.$baseGroup;
        } else {
            return $baseGroup;
        }
    }

    /**
     * Check if the current user can administrate the GroupPathProvider object
     * @param AjxpGroupPathProvider $provider
     * @return bool
     */
    public function canAdministrate(AjxpGroupPathProvider $provider)
    {
        $pGP = $provider->getGroupPath();
        if(empty($pGP)) $pGP = "/";
        if($this->getGroupPath() == null) return true;
        return (strpos($pGP, $this->getGroupPath(), 0) === 0);
    }

    /**
     * Check if the current user can assign administration for the GroupPathProvider object
     * @param AjxpGroupPathProvider $provider
     * @return bool
     */
    public function canSee(AjxpGroupPathProvider $provider)
    {
        $pGP = $provider->getGroupPath();
        if(empty($pGP)) $pGP = "/";
        if($this->getGroupPath() == null || $pGP == null) return true;
        return (strpos($this->getGroupPath(), $pGP, 0) === 0);
    }

    public function recomputeMergedRole()
    {
        if (!count($this->roles)) {
            throw new \Exception("Empty role, this is not normal");
        }
        uksort($this->roles, array($this, "orderRoles"));
        $keys = array_keys($this->roles);
        $this->mergedRole =  clone $this->roles[array_shift($keys)];
        if (count($this->roles) > 1) {
            $this->parentRole = $this->mergedRole;
        }
        $index = 0;
        foreach ($this->roles as $role) {
            if ($index > 0) {
                $this->mergedRole = $role->override($this->mergedRole);
                if($index < count($this->roles) -1 ) $this->parentRole = $role->override($this->parentRole);
            }
            $index ++;
        }
        if ($this->hasParent() && isSet($this->parentRole)) {
            // It's a shared user, we don't want it to inherit the rights...
            $this->parentRole->clearAcls();
            //... but we want the parent user's role, filtered with inheritable properties only.
            $stretchedParentUserRole = RolesService::limitedRoleFromParent($this->parentUser);
            if ($stretchedParentUserRole !== null) {
                $this->parentRole = $stretchedParentUserRole->override($this->parentRole);  //$this->parentRole->override($stretchedParentUserRole);
                // REAPPLY SPECIFIC "SHARED" ROLES
                foreach ($this->roles as $role) {
                    if(! $role->autoAppliesTo("shared")) continue;
                    $this->parentRole = $role->override($this->parentRole);
                }
            }
            $this->mergedRole = $this->personalRole->override($this->parentRole);  // $this->parentRole->override($this->personalRole);
        }
    }

    public function getMergedRole()
    {
        return $this->mergedRole;
    }

    public function getPersonalRole()
    {
        return $this->personalRole;
    }

    public function updatePersonalRole(AJXP_Role $role)
    {
        $this->personalRole = $role;
    }

    protected function migrateRightsToPersonalRole()
    {
        $changes = 0;
        $this->personalRole = new AJXP_Role("AJXP_USR_"."/".$this->id);
        $this->roles["AJXP_USR_"."/".$this->id] = $this->personalRole;
        foreach ($this->rights as $rightKey => $rightValue) {
            if ($rightKey == "ajxp.actions" && is_array($rightValue)) {
                foreach ($rightValue as $repoId => $repoData) {
                    foreach ($repoData as $actionName => $actionState) {
                        $this->personalRole->setActionState("plugin.all", $actionName, $repoId, $actionState);
                        $changes++;
                    }
                }
                unset($this->rights[$rightKey]);
            }
            if(strpos($rightKey, "ajxp.") === 0) continue;
            $this->personalRole->setAcl($rightKey, $rightValue);
            $changes++;
            unset($this->rights[$rightKey]);
        }
        // Move old CUSTOM_DATA values to personal role parameter
        $customValue = $this->getPref("CUSTOM_PARAMS");
        $custom = ConfService::getConfStorageImpl()->getOption("CUSTOM_DATA");
        if (is_array($custom) && count($custom)) {
            foreach ($custom as $key => $value) {
                if (isSet($customValue[$key])) {
                    $this->personalRole->setParameterValue(ConfService::getConfStorageImpl()->getId(), $key, $customValue[$key]);
                }
            }
        }

        // Move old WALLET values to personal role parameter
        $wallet = $this->getPref("AJXP_WALLET");
        if (is_array($wallet) && count($wallet)) {
            foreach ($wallet as $repositoryId => $walletData) {
                $repoObject = RepositoryService::getRepositoryById($repositoryId);
                if($repoObject == null) continue;
                $accessType = "access.".$repoObject->getAccessType();
                foreach ($walletData as $paramName => $paramValue) {
                    $this->personalRole->setParameterValue($accessType, $paramName, $paramValue, $repositoryId);
                }
            }
        }
        return $changes;
    }

    protected function orderRoles($r1, $r2)
    {
        // One group and something else
        if(strpos($r1, "AJXP_GRP_") === 0 && strpos($r2, "AJXP_GRP_") === FALSE) return -1;
        if(strpos($r2, "AJXP_GRP_") === 0 && strpos($r1, "AJXP_GRP_") === FALSE) return 1;

        // Usr role and something else
        if(strpos($r1, "AJXP_USR_") === 0) return 1;
        if(strpos($r2, "AJXP_USR_") === 0) return -1;

        // Two groups, sort by string, will magically keep group hierarchy
        if(strpos($r1, "AJXP_GRP_") === 0 && strpos($r2, "AJXP_GRP_") === 000) {
            return strcmp($r1,$r2);
        }

        // Two roles: if sticky and something else, always last.
        if(isSet($this->rights["ajxp.roles.sticky"])){
            $sticky = $this->rights["ajxp.roles.sticky"];
            if(isSet($sticky[$r1]) && !isSet($sticky[$r2])){
                return 1;
            }
            if(isSet($sticky[$r2]) && !isSet($sticky[$r1])){
                return -1;
            }
        }

        // Two roles - Try to get sorting order
        if(isSet($this->rights["ajxp.roles.order"])){
            return $this->rights["ajxp.roles.order"][$r1] - $this->rights["ajxp.roles.order"][$r2];
        }else{
            return strcmp($r1,$r2);
        }
    }

    /**
     * @param array $roles
     * @param boolean $checkBoolean
     * @return array
     */
    protected function filterRolesForSaving($roles, $checkBoolean)
    {
        $res = array();
        foreach ($roles as $rName => $status) {
            if($checkBoolean &&  !$status) continue;
            if(strpos($rName, "AJXP_GRP_/") === 0) continue;
            $res[$rName] = $status;
        }
        return $res;
    }

    protected $lastSessionSerialization = 0;

    public function __sleep(){
        $this->lastSessionSerialization = time();
        return array("id", "hasAdmin", "rights", "prefs", "bookmarks", "version", "roles", "parentUser", "hidden", "groupPath", "personalRole", "lastSessionSerialization");
    }

    public function __wakeup(){
        $this->storage = ConfService::getConfStorageImpl();
        if(!is_object($this->personalRole)){
            $this->personalRole = RolesService::getRole("AJXP_USR_/" . $this->getId());
        }
        $this->recomputeMergedRole();
    }

    public function reloadRolesIfRequired(){
        if($this->lastSessionSerialization && count($this->roles)
            && $this->storage->rolesLastUpdated(array_keys($this->roles)) > $this->lastSessionSerialization){

            $newRoles = RolesService::getRolesList(array_keys($this->roles));
            foreach($newRoles as $rId => $newRole){
                if(strpos($rId, "AJXP_USR_/") === 0){
                    $this->personalRole = $newRole;
                    $this->roles[$rId] = $this->personalRole;
                }else{
                    $this->roles[$rId] = $newRole;
                }
            }
            $this->recomputeMergedRole();
            return true;

        }
        return false;
    }

}
