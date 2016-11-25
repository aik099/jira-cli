<?php
/**
 * This file is part of the Jira-CLI library.
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @copyright Alexander Obuhovich <aik.bold@gmail.com>
 * @link      https://github.com/console-helpers/jira-cli
 */

namespace ConsoleHelpers\JiraCLI\Issue;


use chobie\Jira\Api;
use chobie\Jira\Issue;
use chobie\Jira\Issues\Walker;

class IssueCloner
{

	/**
	 * Jira REST client.
	 *
	 * @var Api
	 */
	protected $jiraApi;

	/**
	 * Specifies custom fields to copy during backporting.
	 *
	 * @var array
	 */
	private $_copyCustomFields = array(
		'Change Log Group', 'Change Log Message',
	);

	/**
	 * Custom fields map.
	 *
	 * @var array
	 */
	private $_customFieldsMap = array();

	/**
	 * Fields to query during issue search.
	 *
	 * @var array
	 */
	protected $queryFields = array('summary', 'issuelinks');

	/**
	 * IssueCloner constructor.
	 *
	 * @param Api $jira_api Jira REST client.
	 */
	public function __construct(Api $jira_api)
	{
		$this->jiraApi = $jira_api;

		$this->jiraApi->setOptions(0); // Don't expand fields.
	}

	/**
	 * Returns issues.
	 *
	 * @param string $jql       JQL.
	 * @param string $link_name Link name.
	 *
	 * @return array
	 */
	public function getIssues($jql, $link_name)
	{
		$this->_buildCustomFieldsMap();

		$walker = new Walker($this->jiraApi);
		$walker->push($jql, implode(',', $this->_getQueryFields()));

		$ret = array();

		foreach ( $walker as $issue ) {
			$linked_issue = $this->_getLinkedIssue($issue, $link_name);

			if ( is_object($linked_issue) && $this->isAlreadyProcessed($issue, $linked_issue) ) {
				continue;
			}

			$ret[] = array($issue, $linked_issue);
		}

		return $ret;
	}

	/**
	 * Builds custom field map.
	 *
	 * @return void
	 */
	private function _buildCustomFieldsMap()
	{
		foreach ( $this->jiraApi->getFields() as $field_key => $field_data ) {
			if ( substr($field_key, 0, 12) === 'customfield_' ) {
				$this->_customFieldsMap[$field_data['name']] = $field_key;
			}
		}
	}

	/**
	 * Returns query fields.
	 *
	 * @return array
	 */
	private function _getQueryFields()
	{
		$ret = $this->queryFields;

		foreach ( $this->_copyCustomFields as $custom_field ) {
			if ( isset($this->_customFieldsMap[$custom_field]) ) {
				$ret[] = $this->_customFieldsMap[$custom_field];
			}
		}

		return $ret;
	}

	/**
	 * Returns issue, which backports given issue.
	 *
	 * @param Issue  $issue     Issue.
	 * @param string $link_name Link name.
	 *
	 * @return Issue|null
	 */
	private function _getLinkedIssue(Issue $issue, $link_name)
	{
		foreach ( $issue->get('issuelinks') as $issue_link ) {
			if ( $issue_link['type']['name'] !== $link_name ) {
				continue;
			}

			if ( array_key_exists('inwardIssue', $issue_link) ) {
				$linked_issue = new Issue($issue_link['inwardIssue']);

				if ( $this->isLinkAccepted($issue, $linked_issue) ) {
					return $linked_issue;
				}
			}
		}

		return null;
	}

	/**
	 * Creates backports issues.
	 *
	 * @param Issue  $issue         Issue.
	 * @param string $project_key   Project key.
	 * @param string $link_name     Link name.
	 * @param array  $component_ids Component IDs.
	 *
	 * @return void
	 * @throws \RuntimeException When failed to create an issue.
	 */
	public function createLinkedIssue(Issue $issue, $project_key, $link_name, array $component_ids)
	{
		$create_fields = array(
			'description' => 'See ' . $issue->getKey() . '.',
			'components' => array(),
		);

		foreach ( $this->_copyCustomFields as $custom_field ) {
			if ( isset($this->_customFieldsMap[$custom_field]) ) {
				$custom_field_id = $this->_customFieldsMap[$custom_field];
				$create_fields[$custom_field_id] = $this->getIssueCustomField($issue, $custom_field_id);
			}
		}

		foreach ( $component_ids as $component_id ) {
			$create_fields['components'][] = array('id' => $component_id);
		}

		$create_issue_result = $this->jiraApi->createIssue(
			$project_key,
			$issue->get('summary'),
			$this->getChangelogEntryIssueTypeId(),
			$create_fields
		);

		$raw_create_issue_result = $create_issue_result->getResult();

		if ( array_key_exists('errors', $raw_create_issue_result) ) {
			throw new \RuntimeException(sprintf(
				'Failed to create linked issue for "%s" issue. Errors: ' . PHP_EOL . '%s',
				$issue->getKey(),
				print_r($raw_create_issue_result['errors'], true)
			));
		}

		$issue_link_result = $this->jiraApi->api(
			Api::REQUEST_POST,
			'/rest/api/2/issueLink',
			array(
				'type' => array('name' => $link_name),
				'inwardIssue' => array('key' => $raw_create_issue_result['key']),
				'outwardIssue' => array('key' => $issue->getKey()),
			)
		);
	}

	/**
	 * Determines if link was already processed.
	 *
	 * @param Issue $issue        Issue.
	 * @param Issue $linked_issue Linked issue.
	 *
	 * @return boolean
	 */
	protected function isAlreadyProcessed(Issue $issue, Issue $linked_issue)
	{
		return true;
	}

	/**
	 * Determines if link is accepted.
	 *
	 * @param Issue $issue        Issue.
	 * @param Issue $linked_issue Linked issue.
	 *
	 * @return boolean
	 */
	protected function isLinkAccepted(Issue $issue, Issue $linked_issue)
	{
		return true;
	}

	/**
	 * Returns ID of "Changelog Entry" issue type.
	 *
	 * @return integer
	 * @throws \LogicException When "Changelog Entry" issue type wasn't found.
	 */
	protected function getChangelogEntryIssueTypeId()
	{
		static $issue_type_id;

		if ( !isset($issue_type_id) ) {
			foreach ( $this->jiraApi->getIssueTypes() as $issue_type ) {
				if ( $issue_type->getName() === 'Changelog Entry' ) {
					$issue_type_id = $issue_type->getId();
					break;
				}
			}

			if ( !isset($issue_type_id) ) {
				throw new \LogicException('The "Changelog Entry" issue type not found.');
			}
		}

		return $issue_type_id;
	}

	/**
	 * Returns custom field value.
	 *
	 * @param Issue  $issue           Issue.
	 * @param string $custom_field_id Custom field ID.
	 *
	 * @return mixed
	 */
	protected function getIssueCustomField(Issue $issue, $custom_field_id)
	{
		$custom_field_data = $issue->get($custom_field_id);

		if ( is_array($custom_field_data) ) {
			return array('value' => $custom_field_data['value']);
		}

		return $custom_field_data;
	}

	/**
	 * Returns issue status name.
	 *
	 * @param Issue $issue Issue.
	 *
	 * @return string
	 */
	public function getIssueStatusName(Issue $issue)
	{
		$status = $issue->get('status');

		return $status['name'];
	}

}
