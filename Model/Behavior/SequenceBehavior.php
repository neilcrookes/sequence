<?php
/**
 * SequenceBehavior maintains a contiguous sequence of integers (starting at 0
 * or other configurable integer) in a selected column, such as `order`, for all
 * records in a table, or groups of records identified by one or more
 * 'group_fields', when adding, editing (including moving groups) or deleting
 * records.
 *
 * Consider the following simple example with no groups:
 * Record  Order
 * A       0
 * B       1
 * C       2
 * D       3
 * E       4
 * F       5
 * G       6
 *  - Save
 *    - If adding new record
 *      - If order not specified e.g. Record H added:
 *          Inserts H at end of list i.e. highest order + 1
 *      - If order specified e.g. Record H added at position 3:
 *          Inserts at specified order
 *          Increments order of all other records whose order >= order of
 *           inserted record i.e. D, E, F & G get incremented
 *    - If editing existing record:
 *      - If order not specified and group not specified, or same
 *          No Action
 *      - If order not specified but group specified and different:
 *          Decrement order of all records whose order > old order in the old
 *           group, and change order to highest order of new groups + 1
 *      - If order specified:
 *        - If new order < old order e.g. record E moves from 4 to 2
 *            Increments order of all other records whose order >= new order and
 *             order < old order i.e. order of C & D get incremented
 *        - If new order > old order e.g. record C moves from 2 to 4
 *            Decrements order of all other records whose order > old order and
 *             <= new order i.e. order of D & E get decremented
 *        - If new order == old order
 *            No action
 *  - Delete
 *      Decrement order of all records whose order > order of deleted record
 *
 * @author Neil Crookes <neil@neilcrookes.com>
 * @link http://www.neilcrookes.com
 * @copyright (c) 2010 Neil Crookes
 * @license MIT License - http://www.opensource.org/licenses/mit-license.php
 * @link http://www.neilcrookes.com
 *
 * @package cake
 * @subpackage cake.base
 */
class SequenceBehavior extends ModelBehavior {

  /**
   * Default settings for a model that has this behavior attached.
   *
   * @var array
   * @access protected
   */
  protected $_defaults = array(
    'order_field' => 'order',
    'group_fields' => false,
    'start_at' => 0,
  );

  /**
   * Stores the current order of the record
   *
   * @var integer
   */
  protected $_oldOrder;

  /**
   * Stores the new order of the record
   *
   * @var integer
   */
  protected $_newOrder;

  /**
   * Stores the current values of the group fields for the record, before it's
   * saved or deleted, retrieved from the database
   *
   * @var array
   */
  protected $_oldGroups;

  /**
   * Stores the new values of the group fields for the record, before it's saved
   * or deleted, retrieved from the model->data
   *
   * @var array
   */
  protected $_newGroups;

  /**
   * Stores the update instructions with keys for update, which is the actual
   * <field> => <field> +/- 1 part, and for conditions, which identify the
   * records to which the update will be applied, and optional group_values for
   * the case where you are editing a record, and the groups are changing and
   * the new order in the new group is specified.
   *
   * @var array
   */
  protected $_update;

  /**
   * Merges the passed config array defined in the model's actsAs property with
   * the behavior's defaults and stores the resultant array in this->settings
   * for the current model.
   *
   * @param Model $Model Model object that method is triggered on
   * @param array $config Configuration options include:
   * - order_field - The database field name that stores the sequence number.
   *   Default is order.
   * - group_fields - Array of database field names that identify a single
   *   group of records that need to form a contiguous sequence. Default is
   *   false, i.e. no group fields
   * - start_at - You can start your sequence numbers at 0 or 1 or any other.
   *   Default is 0
   * @return void
   */
  public function setup(Model $Model, $config = array()) {
    // If config is a string, assume it's the order field
    if (is_string($config)) {
      $config = array('order_field' => $config);
    }
    // Merge defaults with passed config and put in settings for model
    $this->settings[$Model->alias] = array_merge($this->_defaults, $config);
    // Set the escaped order field setting
    $this->settings[$Model->alias]['escaped_order_field'] = $Model->escapeField($this->settings[$Model->alias]['order_field']);
    // If group_fields in settings is false, return now as remainder not needed
    if ($this->settings[$Model->alias]['group_fields'] === false) {
      return;
    }
    // If group_fields in settings is a string, make it an array
    if (is_string($this->settings[$Model->alias]['group_fields'])) {
      $this->settings[$Model->alias]['group_fields'] = array($this->settings[$Model->alias]['group_fields']);
    }
    // Set the group fields as the keys so we can add the escaped version as the
    // values
    $this->settings[$Model->alias]['group_fields'] = array_flip($this->settings[$Model->alias]['group_fields']);
    foreach ($this->settings[$Model->alias]['group_fields'] as $groupField => $null) {
      $this->settings[$Model->alias]['group_fields'][$groupField] = $Model->escapeField($groupField);
    }

  }

  /**
   * Adds order value if not already set in query data
   *
   * @param Model $Model Model object that method is triggered on
   * @param array $queryData Original queryData
   * @return array Modified queryData
   */
  public function beforeFind(Model $Model, $queryData) {

    // order can can sometimes be not set, or empty, or array(0 => null)
    if (!isset($queryData['order']) || empty($queryData['order']) || (
        is_array($queryData['order'])
        && count($queryData['order']) == 1
        && empty($queryData['order'][0])
      )) {
      $queryData['order'] = $this->settings[$Model->alias]['escaped_order_field'];
    }
    return $queryData;
  }

  /**
   * Sets update actions and their conditions which get executed in after save,
   * affects model->data when necessary
   *
   * @param Model $Model Model object that method is triggered on
   * @param array $options
   * @return boolean Always true otherwise model will not save
   */
  public function beforeSave(Model $Model, $options = array()) {
    $this->_update[$Model->alias] = array();
    // Sets new order and new groups from model->data
    $this->_setNewOrder($Model);
    $this->_setNewGroups($Model);

    $orderField = $this->settings[$Model->alias]['order_field'];
    $escapedOrderField = $this->settings[$Model->alias]['escaped_order_field'];

    // Adding
    if (!$Model->id) {

      // Order not specified
      if (!isset($this->_newOrder[$Model->alias])) {

        // Insert at end of list
        $Model->data[$Model->alias][$orderField] = $this->_getHighestOrder($Model, $this->_newGroups[$Model->alias]) + 1;

      // Order specified
      } else {

        // The updateAll called in afterSave uses old groups values as default
        // conditions to restrict which records are updated, so set old groups
        // to new groups as old isn't set.
        $this->_oldGroups[$Model->alias] = $this->_newGroups[$Model->alias];

        // Insert and increment order of records it's inserted before
        $this->_update[$Model->alias][] = array(
          'action' => array(
            $escapedOrderField => $escapedOrderField . ' + 1'
          ),
          'conditions' => array(
            $escapedOrderField . ' >=' => $this->_newOrder[$Model->alias]
          ),
        );

      }

    // Editing
    } else {

      // No action if no new order or group specified
      if (!isset($this->_newOrder[$Model->alias]) && !isset($this->_newGroups[$Model->alias])) {
        return true;
      }

      $this->_setOldOrder($Model);
      $this->_setOldGroups($Model);

      // No action if new and old group and order same
      if ($this->_newOrder[$Model->alias] == $this->_oldOrder[$Model->alias]
      && serialize($this->_newGroups[$Model->alias]) == serialize($this->_oldGroups[$Model->alias])) {
        return true;
      }

      // If changing group
      if ($this->_newGroups[$Model->alias] && $this->_newGroups[$Model->alias] != $this->_oldGroups[$Model->alias]) {

        // Decrement records in old group with higher order than moved record old order
        $this->_update[$Model->alias][] = array(
          'action' => array(
            $escapedOrderField => $escapedOrderField . ' - 1'
          ),
          'conditions' => array(
            $escapedOrderField . ' >=' => $this->_oldOrder[$Model->alias],
          ),
        );

        // Order not specified
        if (!isset($this->_newOrder[$Model->alias])) {

          // Insert at end of new group
          $Model->data[$Model->alias][$orderField] = $this->_getHighestOrder($Model, $this->_newGroups[$Model->alias]) + 1;

        // Order specified
        } else {

          // Increment records in new group with higher order than moved record new order
          $this->_update[$Model->alias][] = array(
            'action' => array(
              $escapedOrderField => $escapedOrderField . ' + 1'
            ),
            'conditions' => array(
              $escapedOrderField . ' >=' => $this->_newOrder[$Model->alias],
            ),
            'group_values' => $this->_newGroups[$Model->alias],
          );
        }

      // Same group
      } else {

        // If moving up
        if ($this->_newOrder[$Model->alias] < $this->_oldOrder[$Model->alias]) {

          // Increment order of those in between
          $this->_update[$Model->alias][] = array(
            'action' => array(
              $escapedOrderField => $escapedOrderField . ' + 1'
            ),
            'conditions' => array(
              array($escapedOrderField . ' >=' => $this->_newOrder[$Model->alias]),
              array($escapedOrderField . ' <' => $this->_oldOrder[$Model->alias]),
            ),
          );

        // Moving down
        } else {
          // Decrement order of those in between
          $this->_update[$Model->alias][] = array(
            'action' => array(
              $escapedOrderField => $escapedOrderField . ' - 1'
            ),
            'conditions' => array(
              array($escapedOrderField . ' >' => $this->_oldOrder[$Model->alias]),
              array($escapedOrderField . ' <=' => $this->_newOrder[$Model->alias]),
            ),
          );
        }
      }
    }
    return true;
  }

  /**
   * Called automatically after model gets saved, triggers order updates
   *
   * @param Model $Model Model object that method is triggered on
   * @param boolean $created Whether the record was created or not
   * @param array $options
   * @return boolean
   */
  public function afterSave(Model $Model, $created, $options = array()) {
    return $this->_updateAll($Model);

  }

  /**
   * When you delete a record from a set, you need to decrement the order of all
   * records that were after it in the set.
   *
   * @param Model $Model Model object that method is triggered on
   * @param array $options
   * @return boolean Always true
   */
  public function beforeDelete(Model $Model, $options = array()) {

    $this->_update[$Model->alias] = array();

    // Set current order and groups of record to be deleted
    $this->_setOldOrder($Model);
    $this->_setOldGroups($Model);

    $escapedOrderField = $this->settings[$Model->alias]['escaped_order_field'];

    // Decrement records in group with higher order than deleted record
    $this->_update[$Model->alias][] = array(
      'action' => array(
        $escapedOrderField => $escapedOrderField . ' - 1'
      ),
      'conditions' => array(
        $escapedOrderField . ' >' => $this->_oldOrder[$Model->alias],
      ),
    );

    return true;

  }

  /**
   * Called automatically after model gets deleted, triggers order updates
   *
   * @param Model $Model Model object that method is triggered on
   * @return boolean
   */
  public function afterDelete(Model $Model) {

    return $this->_updateAll($Model);

  }

  /**
   * Returns the current highest order of all records in the set. When a new
   * record is added to the set, it is added at the current highest order, plus
   * one.
   *
   * @param Model $Model Model object that method is triggered on
   * @param array $groupValues Array with group field => group values, used for conditions
   * @return integer Value of order field of last record in set
   */
  protected function _getHighestOrder(Model $Model, $groupValues = false) {

    $orderField = $this->settings[$Model->alias]['order_field'];
    $escapedOrderField = $this->settings[$Model->alias]['escaped_order_field'];

    // Conditions for the record set to which this record will be added to.
    $conditions = $this->_conditionsForGroups($Model, $groupValues);

    // Find the last record in the set
    $last = $Model->find('first', array(
      'conditions' => $conditions,
      'recursive' => -1,
      'order' => $escapedOrderField . ' DESC',
    ));

    // If there is a last record (i.e. any) in the set, return the it's order
    if ($last) {
      return $last[$Model->alias][$orderField];
    }

    // If there isn't any records in the set, return the start number minus 1
    return ((int)$this->settings[$Model->alias]['start_at'] - 1);

  }

  /**
   * If editing or deleting a record, set the oldOrder property to the current
   * order in the database.
   *
   * @param Model $Model Model object that method is triggered on
   * @return void
   */
  protected function _setOldOrder(Model $Model) {

    $this->_oldOrder[$Model->alias] = null;

    $orderField = $this->settings[$Model->alias]['order_field'];

    // Set old order to record's current order in database
    $this->_oldOrder[$Model->alias] = $Model->field($orderField);

  }

  /**
   * If editing or deleting a record, set the oldGroups property to the current
   * group values in the database for each group field for this model.
   *
   * @param Model $Model Model object that method is triggered on
   * @return void
   */
  protected function _setOldGroups(Model $Model) {

    $this->_oldGroups[$Model->alias] = null;

    $groupFields = $this->settings[$Model->alias]['group_fields'];

    // If this model does not have any groups, return
    if ($groupFields === false) {
      return;
    }

    // Set oldGroups property with key of group field and current value of group
    // field from db
    foreach ($groupFields as $groupField => $escapedGroupField) {
      $this->_oldGroups[$Model->alias][$groupField] = $Model->field($groupField);
    }

  }

  /**
   * Sets new order property for current model to value in model->data
   *
   * @param Model $Model Model object that method is triggered on
   * @return void
   */
  protected function _setNewOrder(Model $Model) {

    $this->_newOrder[$Model->alias] = null;

    $orderField = $this->settings[$Model->alias]['order_field'];

    if (!isset($Model->data[$Model->alias][$orderField])) {
      return;
    }

    $this->_newOrder[$Model->alias] = $Model->data[$Model->alias][$orderField];

  }

  /**
   * Set new groups property with keys of group field and values from
   * $Model->data, if set.
   *
   * @param Model $Model Model object that method is triggered on
   * @return void
   */
  protected function _setNewGroups(Model $Model) {

    $this->_newGroups[$Model->alias] = null;

    $groupFields = $this->settings[$Model->alias]['group_fields'];

    // Return if this model has not group fields
    if ($groupFields === false) {
      return;
    }

    foreach ($groupFields as $groupField => $escapedGroupField) {

      if (isset($Model->data[$Model->alias][$groupField])) {

        $this->_newGroups[$Model->alias][$groupField] = $Model->data[$Model->alias][$groupField];

      }

    }

  }

  /**
   * Returns array of conditions for restricting a record set according to the
   * model's group fields setting.
   *
   * @param Model $Model Model object that method is triggered on
   * @param array $groupValues Array of group field => group value pairs
   * @return array Array of escaped group field => group value pairs
   */
  protected function _conditionsForGroups(Model $Model, $groupValues = false) {

    $conditions = array();

    $groupFields = $this->settings[$Model->alias]['group_fields'];

    // Return if this model has not group fields
    if ($groupFields === false) {
      return $conditions;
    }

    // By default, if group values are not specified, use the old group fields
    if ($groupValues === false) {

      $groupValues = $this->_oldGroups[$Model->alias];

    }

    // Set up conditions for each group field
    foreach ($groupFields as $groupField => $escapedGroupField) {

      $groupValue = null;

      if (isset($groupValues[$groupField])) {
        $groupValue = $groupValues[$groupField];
      }

      $conditions[] = array(
        $escapedGroupField => $groupValue,
      );

    }

    return $conditions;

  }

  /**
   * When doing any update all calls, you want to avoid updating the record
   * you've just modified, as the order will have been set already, so exclude
   * it with some conditions.
   *
   * @param Model $Model Model object that method is triggered on
   * @return array Array Model.primary_key <> => $id
   */
  protected function _conditionsNotCurrent(Model $Model) {

    return array($Model->escapeField($Model->primaryKey) . ' <>' => $Model->id);

  }

  /**
   * Executes the update, if there are any. Called in afterSave and afterDelete.
   *
   * @param Model $Model Model object that method is triggered on
   * @return boolean
   */
  protected function _updateAll(Model $Model) {

    // If there's no update to do
    if (empty($this->_update[$Model->alias])) {
      return true;
    }

    $return = true;

    foreach ($this->_update[$Model->alias] as $update) {

      $groupValues = false;

      if (isset($update['group_values'])) {
        $groupValues = $update['group_values'];
      }

      // Actual conditions for the update are a combination of what's derived in
      // the beforeSave or beforeDelete, and conditions to not the record we've
      // just modified/inserted and conditions to make sure only records in the
      // current record's groups
      $conditions = array_merge(
        $this->_conditionsForGroups($Model, $groupValues),
        $this->_conditionsNotCurrent($Model),
        $update['conditions']
      );

      $success = $Model->updateAll($update['action'], $conditions);

      $return = $return && $success;

    }

    return $return;

  }

}
?>
