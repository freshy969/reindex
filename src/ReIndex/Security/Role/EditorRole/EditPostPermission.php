<?php

/**
 * @file EditorRole/EditPostPermission.php
 * @brief This file contains the EditPostPermission class.
 * @details
 * @author Filippo F. Fadda
 */


//! Permissions for the editor role
namespace ReIndex\Security\Role\EditorRole;


use ReIndex\Security\Role\MemberRole\EditPostPermission as Superclass;
use ReIndex\Enum\State;


/**
 * @copydoc MemberRole::EditPostPermission
 */
class EditPostPermission extends Superclass {


  public function check() {
    if (parent::check())
      return TRUE;
    else
     return (!$this->context->isLocked() && $this->context->state->is(State::CURRENT)) ? TRUE : FALSE;
  }

}