<?php
/**
 * @file RejectRevisionPermission.php
 * @brief This file contains the ${CLASS_NAME} class.
 * @details
 * @author Filippo F. Fadda
 */


namespace ReIndex\Security\Role\ReviewerRole;


use ReIndex\Security\Role\AbstractPermission;


class RejectRevisionPermission extends AbstractPermission {


  public function getDescription() {
    //! @todo: Implement getDescription() method.
  }


  /**
   * @brief Returns `true` if the document revision can be rejected, `false` otherwise.
   * @retval bool
   */
  public function check() {
    if ($this->user->isModerator() && $this->isSubmittedForPeerReview())
      return TRUE;
    else
      return FALSE;
  }

}