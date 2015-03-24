<?php

namespace Westkingdom\GoogleAPIExtensions;

use WestKingdom\GoogleAPIExtensions\GroupsController;
use WestKingdom\GoogleAPIExtensions\Journal;
use WestKingdom\GoogleAPIExtensions\Utils;

class Updater {
  protected $ctrl;
  protected $journal;

  /**
   * @param $ctrl control object that does actual actions
   * @param $state initial state
   */
  function __construct(GroupsController $ctrl, Journal $journal) {
    $this->ctrl = $ctrl;
    $this->journal = $journal;
  }

  function setJournal($journal) {
    $this->journal = $journal;
  }

  /**
   * Update our group memberships
   *
   *
   * @param $memberships nested associative array
   *    BRANCHES contain LISTS and ALIASES.
   *
   *      branchname => array(lists => ..., aliases=> ...)
   *
   *      LISTS contain groups.
   *
   *        lists => array(group1 => ..., group2 => ...)
   *
   *          Groups can be one of three formats:
   *
   *            string - a group with one member; data is email address
   *
   *            simple array of strings - email addresses of members
   *
   *            associative array - element 'members' contains email addresses of members
   *
   *      ALIASES are structured just like groups.
   *
   *    The difference between a LIST and an ALIAS is that a list is
   *    expected to keep an archive of all email that is sent to it,
   *    and an alias just passes the email through.
   */
  function update($memberships, $existingState) {
    $this->ctrl->begin();
    $memberships = Utils::normalize($memberships);

    foreach ($memberships as $branch => $officesLists) {
      $offices = $officesLists['lists'];
      // Next, update or insert, depending on whether this branch is new.
      if (array_key_exists($branch, $existingState)) {
        $this->updateBranch($branch, $offices, $existingState[$branch]['lists']);
      }
      else {
        $this->insertBranch($branch, $offices);
      }
    }
    // Finally, delete any branch that is no longer with us.
    foreach ($existingState as $branch => $offices) {
      if (!array_key_exists($branch, $memberships)) {
        $this->deleteBranch($branch, $offices);
      }
    }
    $this->ctrl->complete();
  }

  function updateBranch($branch, $updateOffices, $existingOffices) {
    foreach ($updateOffices as $officename => $officeData) {
      if (array_key_exists($officename, $existingOffices)) {
        $this->updateOfficeMembers($branch, $officename, $officeData['members'], $existingOffices[$officename]['members']);
        $newAlternateAddresses = Utils::getAlternateAddresses($branch, $officename, $officeData);
        $existingAlternateAddresses = Utils::getAlternateAddresses($branch, $officename, $existingOffices[$officename]);
        $this->updateOfficeAlternateAddresses($branch, $officename, $newAlternateAddresses, $existingAlternateAddresses);
      }
      else {
        $this->insertOffice($branch, $officename, $officeData);
      }
    }
    foreach ($existingOffices as $officename => $officeData) {
      if (!array_key_exists($officename, $updateOffices)) {
        $this->deleteOffice($branch, $officename, $officeData);
      }
    }
  }

  function insertBranch($branch, $newOffices) {
    $this->ctrl->insertBranch($branch);
    $this->updateBranch($branch, $newOffices, array());
  }

  function deleteBranch($branch, $removingOffices) {
    $this->ctrl->deleteBranch($branch);
  }

  function updateOfficeMembers($branch, $officename, $updateMembers, $existingMembers) {
    foreach ($updateMembers as $emailAddress) {
      if (!in_array($emailAddress, $existingMembers)) {
        $this->ctrl->insertMember($branch, $officename, $emailAddress);
      }
    }
    foreach ($existingMembers as $emailAddress) {
      if (!in_array($emailAddress, $updateMembers)) {
        $this->ctrl->removeMember($branch, $officename, $emailAddress);
      }
    }
  }

  function updateOfficeAlternateAddresses($branch, $officename, $newAlternateAddresses, $existingAlternateAddresses) {
    foreach ($newAlternateAddresses as $emailAddress) {
      if (!in_array($emailAddress, $existingAlternateAddresses)) {
        $this->ctrl->insertGroupAlternateAddress($branch, $officename, $emailAddress);
      }
    }
    foreach ($existingAlternateAddresses as $emailAddress) {
      if (!in_array($emailAddress, $newAlternateAddresses)) {
        $this->ctrl->removeGroupAlternateAddress($branch, $officename, $emailAddress);
      }
    }
  }

  function insertOffice($branch, $officename, $officeData) {
    $this->ctrl->insertOffice($branch, $officename, $officeData['properties']);
    $this->updateOfficeMembers($branch, $officename, $officeData['members'], array());
    $newAlternateAddresses = $this->getAlternateAddresses($branch, $officename, $officeData);
    $this->updateOfficeAlternateAddresses($branch, $officename, $newAlternateAddresses, array());
  }

  function deleteOffice($branch, $officename, $officeData) {
    $this->ctrl->deleteOffice($branch, $officename, $officeData['properties']);
  }
}
