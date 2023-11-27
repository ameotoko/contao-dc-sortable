<?php
/**
 * @author Andrey Vinichenko <andrey.vinichenko@gmail.com>
 */

namespace Ameotoko\DCSortableBundle\DataContainer;

use Contao\ArrayUtil;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\CoreBundle\Exception\NotFoundException;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\Database;
use Contao\DC_Table;
use Contao\Image;
use Contao\Input;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class DC_TableSortable extends DC_Table
{
    protected function listView(): string
    {
        $GLOBALS['TL_JAVASCRIPT'][] = System::getContainer()->get('assets.packages')
            ->getUrl('backend.js', 'ameotoko_dc_sortable');

        $table = ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) == self::MODE_TREE_EXTENDED ? $this->ptable : $this->strTable;
        $orderBy = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'] ?? array('id');
        $firstOrderBy = preg_replace('/\s+.*$/', '', $orderBy[0]);

        if (\is_array($this->orderBy) && !empty($this->orderBy[0]))
        {
            $orderBy = $this->orderBy;
            $firstOrderBy = $this->firstOrderBy;
        }

        // Check the default labels (see #509)
        $labelNew = $GLOBALS['TL_LANG'][$this->strTable]['new'] ?? $GLOBALS['TL_LANG']['DCA']['new'];

        $query = "SELECT * FROM " . $this->strTable;

        if (!empty($this->procedure))
        {
            $query .= " WHERE " . implode(' AND ', $this->procedure);
        }

        if (!empty($this->root) && \is_array($this->root))
        {
            $query .= (!empty($this->procedure) ? " AND " : " WHERE ") . "id IN(" . implode(',', array_map('\intval', $this->root)) . ")";
        }

        $db = Database::getInstance();

        if (\is_array($orderBy) && $orderBy[0])
        {
            foreach ($orderBy as $k=>$v)
            {
                list($key, $direction) = explode(' ', $v, 2) + array(null, null);

                $orderBy[$k] = $key;

                // If there is no direction, check the global flag in sorting mode 1 or the field flag in all other sorting modes
                if (!$direction)
                {
                    if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) == self::MODE_SORTED && \in_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] ?? null, array(self::SORT_INITIAL_LETTER_DESC, self::SORT_INITIAL_LETTERS_DESC, self::SORT_DAY_DESC, self::SORT_MONTH_DESC, self::SORT_YEAR_DESC, self::SORT_DESC)))
                    {
                        $direction = 'DESC';
                    }
                    elseif (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['flag'] ?? null, array(self::SORT_INITIAL_LETTER_DESC, self::SORT_INITIAL_LETTERS_DESC, self::SORT_DAY_DESC, self::SORT_MONTH_DESC, self::SORT_YEAR_DESC, self::SORT_DESC)))
                    {
                        $direction = 'DESC';
                    }
                }

                if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['foreignKey']))
                {
                    $chunks = explode('.', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['foreignKey'], 2);
                    $orderBy[$k] = "(SELECT " . Database::quoteIdentifier($chunks[1]) . " FROM " . $chunks[0] . " WHERE " . $chunks[0] . ".id=" . $this->strTable . "." . $key . ")";
                }

                if (\in_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['flag'] ?? null, array(self::SORT_DAY_ASC, self::SORT_DAY_DESC, self::SORT_DAY_BOTH, self::SORT_MONTH_ASC, self::SORT_MONTH_DESC, self::SORT_MONTH_BOTH, self::SORT_YEAR_ASC, self::SORT_YEAR_DESC, self::SORT_YEAR_BOTH)))
                {
                    $orderBy[$k] = "CAST(" . $orderBy[$k] . " AS SIGNED)"; // see #5503
                }

                if ($direction)
                {
                    $orderBy[$k] .= ' ' . $direction;
                }

                if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['eval']['findInSet'] ?? null)
                {
                    if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback'] ?? null))
                    {
                        $strClass = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback'][0];
                        $strMethod = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback'][1];

                        $keys = System::importStatic($strClass)->$strMethod($this);
                    }
                    elseif (\is_callable($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback'] ?? null))
                    {
                        $keys = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options_callback']($this);
                    }
                    else
                    {
                        $keys = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['options'] ?? array();
                    }

                    if (($GLOBALS['TL_DCA'][$this->strTable]['fields'][$key]['eval']['isAssociative'] ?? null) || ArrayUtil::isAssoc($keys))
                    {
                        $keys = array_keys($keys);
                    }

                    $orderBy[$k] = $db->findInSet($v, $keys);
                }
            }

            if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) == self::MODE_SORTED_PARENT)
            {
                $firstOrderBy = 'pid';
                $showFields = $GLOBALS['TL_DCA'][$table]['list']['label']['fields'];

                $query .= " ORDER BY (SELECT " . Database::quoteIdentifier($showFields[0]) . " FROM " . $this->ptable . " WHERE " . $this->ptable . ".id=" . $this->strTable . ".pid), " . implode(', ', $orderBy) . ', id';

                // Set the foreignKey so that the label is translated
                if (!($GLOBALS['TL_DCA'][$table]['fields']['pid']['foreignKey'] ?? null))
                {
                    $GLOBALS['TL_DCA'][$table]['fields']['pid']['foreignKey'] = $this->ptable . '.' . $showFields[0];
                }

                // Remove the parent field from label fields
                array_shift($showFields);
                $GLOBALS['TL_DCA'][$table]['list']['label']['fields'] = $showFields;
            }
            else
            {
                $query .= " ORDER BY " . implode(', ', $orderBy) . ', id';
            }
        }

        $objRowStmt = $db->prepare($query);

        $hasPreviousPage = false;

        if ($this->limit)
        {
            $arrLimit = explode(',', $this->limit) + array(null, null);

            // fetch last record from the previous page to allow sorting on paginated result
            if ($arrLimit[0] > 0) {
                $hasPreviousPage = true;
                $arrLimit[0]--;
                $arrLimit[1]++;
            }

            $objRowStmt->limit($arrLimit[1], $arrLimit[0]); // offset - 1, limit + 1 to get also the last row of the previous page
        }

        $objRow = $objRowStmt->execute(...$this->values);
        $security = System::getContainer()->get('security.helper');

        // Display buttons
        $buttons = ((Input::get('act') == 'select' || $this->ptable) ? '
<a href="' . $this->getReferer(true, $this->ptable) . '" class="header_back" title="' . StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle']) . '" accesskey="b" onclick="Backend.getScrollOffset()">' . $GLOBALS['TL_LANG']['MSC']['backBT'] . '</a> ' : (isset($GLOBALS['TL_DCA'][$this->strTable]['config']['backlink']) ? '
<a href="' . System::getContainer()->get('router')->generate('contao_backend') . '?' . $GLOBALS['TL_DCA'][$this->strTable]['config']['backlink'] . '" class="header_back" title="' . StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['backBTTitle']) . '" accesskey="b" onclick="Backend.getScrollOffset()">' . $GLOBALS['TL_LANG']['MSC']['backBT'] . '</a> ' : '')) . ((Input::get('act') != 'select' && !($GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['config']['notCreatable'] ?? null) && $security->isGranted(ContaoCorePermissions::DC_PREFIX . $this->strTable, new CreateAction($this->strTable))) ? '
<a href="' . ($this->ptable ? $this->addToUrl('act=create' . ((($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) < self::MODE_PARENT) ? '&amp;mode=2' : '') . '&amp;pid=' . $this->intId) : $this->addToUrl('act=create')) . '" class="header_new" title="' . StringUtil::specialchars($labelNew[1] ?? '') . '" accesskey="n" onclick="Backend.getScrollOffset()">' . $labelNew[0] . '</a> ' : '') . $this->generateGlobalButtons();

        $return = Message::generate() . ($buttons ? '<div id="tl_buttons">' . $buttons . '</div>' : '');

        // Return "no records found" message
        if ($objRow->numRows < 1)
        {
            $return .= '
<p class="tl_empty">' . $GLOBALS['TL_LANG']['MSC']['noResult'] . '</p>';
        }

        // List records
        else
        {
            $result = $objRow->fetchAllAssoc();

            // get id of the last row of the previous page and remove it from displayed result set
            $previousSortingID = $hasPreviousPage ? array_shift($result)['id'] : 0;

            $return .= ((Input::get('act') == 'select') ? '
<form id="tl_select" class="tl_form' . ((Input::get('act') == 'select') ? ' unselectable' : '') . '" method="post" novalidate>
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="tl_select">
<input type="hidden" name="REQUEST_TOKEN" value="' . htmlspecialchars(System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue()) . '">' : '') . '
<div class="tl_listing_container list_view" id="tl_listing_sortable"' . $this->getPickerValueAttribute() . '>' . ((Input::get('act') == 'select' || $this->strPickerFieldType == 'checkbox') ? '
<div class="tl_select_trigger">
<label for="tl_select_trigger" class="tl_select_label">' . $GLOBALS['TL_LANG']['MSC']['selectAll'] . '</label> <input type="checkbox" id="tl_select_trigger" onclick="Backend.toggleCheckboxes(this)" class="tl_tree_checkbox">
</div>' : '') . '
<table class="tl_listing' . (($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null) ? ' showColumns' : '') . ($this->strPickerFieldType ? ' picker unselectable' : '') . '">';

            // Automatically add the "order by" field as last column if we do not have group headers
            if ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null)
            {
                $blnFound = false;

                // Extract the real key and compare it to $firstOrderBy
                foreach ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['fields'] as $f)
                {
                    if (strpos($f, ':') !== false)
                    {
                        list($f) = explode(':', $f, 2);
                    }

                    if ($firstOrderBy == $f)
                    {
                        $blnFound = true;
                        break;
                    }
                }

                if (!$blnFound && $firstOrderBy !== 'sorting')
                {
                    $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['fields'][] = $firstOrderBy;
                }
            }

            // Generate the table header if the "show columns" option is active
            if ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null)
            {
                $return .= '
<thead>
  <tr>';

                foreach ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['fields'] as $f)
                {
                    if (strpos($f, ':') !== false)
                    {
                        list($f) = explode(':', $f, 2);
                    }

                    $return .= '
    <th class="tl_folder_tlist col_' . $f . (($f == $firstOrderBy) ? ' ordered_by' : '') . '">' . (\is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['label'] ?? null) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['label'][0] : ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$f]['label'] ?? $f)) . '</th>';
                }

                $return .= '
    <th class="tl_folder_tlist tl_right_nowrap"></th>
  </tr>
</thead>
<tbody data-id="' . $previousSortingID . '">';
            }

            // Process result and add label and buttons
            $remoteCur = false;
            $groupclass = 'tl_folder_tlist';

            foreach ($result as $row)
            {
                // Improve performance for $dc->getCurrentRecord($id);
                static::setCurrentRecordCache($row['id'], $this->strTable, $row);

                $this->denyAccessUnlessGranted(ContaoCorePermissions::DC_PREFIX . $this->strTable, new ReadAction($this->strTable, $row));

                $this->current[] = $row['id'];
                $label = $this->generateRecordLabel($row, $this->strTable);

                // Build the sorting groups
                if (($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['mode'] ?? null) > 0)
                {
                    $current = $row[$firstOrderBy];
                    $orderBy = $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['fields'] ?? array('id');
                    $sortingMode = (\count($orderBy) == 1 && $firstOrderBy == $orderBy[0] && ($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['flag'] ?? null)) ? $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['flag'] : ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$firstOrderBy]['flag'] ?? null);
                    $remoteNew = $this->formatCurrentValue($firstOrderBy, $current, $sortingMode);

                    // Add the group header
                    if (($remoteNew != $remoteCur || $remoteCur === false) && !($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null) && !($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['disableGrouping'] ?? null))
                    {
                        $group = $this->formatGroupHeader($firstOrderBy, $remoteNew, $sortingMode, $row);
                        $remoteCur = $remoteNew;

                        $return .= '
  <tr>
    <th colspan="2" class="' . $groupclass . '">' . $group . '</th>
  </tr>';
                        $groupclass = 'tl_folder_list';
                    }
                }

                $return .= '
  <tr class="' . ((string) ($row['tstamp'] ?? null) === '0' ? 'draft ' : '') . 'click2edit toggle_select hover-row" data-id="' . $row['id'] . '">
    ';

                $colspan = 1;

                // Handle strings and arrays
                if (!($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null))
                {
                    $label = \is_array($label) ? implode(' ', $label) : $label;
                }
                elseif (!\is_array($label))
                {
                    $label = array($label);
                    $colspan = \count($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['fields'] ?? array());
                }

                // Show columns
                if ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null)
                {
                    foreach ($label as $j=>$arg)
                    {
                        $field = $GLOBALS['TL_DCA'][$this->strTable]['list']['label']['fields'][$j] ?? null;

                        if (isset($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['foreignKey']))
                        {
                            if ($arg)
                            {
                                $key = explode('.', $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['foreignKey'], 2);

                                $reference = $db
                                    ->prepare("SELECT " . Database::quoteIdentifier($key[1]) . " AS value FROM " . $key[0] . " WHERE id=?")
                                    ->limit(1)
                                    ->execute($arg);

                                if ($reference->numRows)
                                {
                                    $arg = $reference->value;
                                }
                            }

                            $value = $arg ?: '-';
                        }
                        else
                        {
                            $value = (string) $arg !== '' ? $arg : '-';
                        }

                        $return .= '<td colspan="' . $colspan . '" class="tl_file_list col_' . explode(':', $field, 2)[0] . ($field == $firstOrderBy ? ' ordered_by' : '') . '">' . $value . '</td>';
                    }
                }
                else
                {
                    $return .= '<td class="tl_file_list">' . $label . '</td>';
                }

                // Buttons ($row, $table, $root, $blnCircularReference, $childs, $previous, $next)
                $return .= ((Input::get('act') == 'select') ? '
    <td class="tl_file_list tl_right_nowrap"><input type="checkbox" name="IDS[]" id="ids_' . $row['id'] . '" class="tl_tree_checkbox" value="' . $row['id'] . '"></td>' : '
    <td class="tl_file_list tl_right_nowrap">' . $this->generateButtons($row, $this->strTable, $this->root) . ($this->strPickerFieldType ? $this->getPickerInputField($row['id']) : '') . '</td>') . '
  </tr>';
            }

            // Close the table body if the "show columns" option is active
            if ($GLOBALS['TL_DCA'][$this->strTable]['list']['label']['showColumns'] ?? null)
            {
                $return .= '</tbody>';
            }

            // Close the table
            $return .= '
</table>
<script>
  window.location.search.search(/id=[0-9]*/) === -1 && history.replaceState({id:"placeholder"}, document.title, window.location.href + "&id=0" + "&rt=' . htmlspecialchars(System::getContainer()->get('contao.csrf.token_manager')->getDefaultTokenValue()) . '");
  AppBackend.makeSortable("#tl_listing_sortable tbody");
</script>' . ($this->strPickerFieldType == 'radio' ? '
<div class="tl_radio_reset">
<label for="tl_radio_reset" class="tl_radio_label">' . $GLOBALS['TL_LANG']['MSC']['resetSelected'] . '</label> <input type="radio" name="picker" id="tl_radio_reset" value="" class="tl_tree_radio">
</div>' : '') . '
</div>';

            // Add another panel at the end of the page
            if (strpos($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['panelLayout'] ?? '', 'limit') !== false)
            {
                $return .= $this->paginationMenu();
            }

            // Close the form
            if (Input::get('act') == 'select')
            {
                // Submit buttons
                $arrButtons = array();

                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null))
                {
                    $arrButtons['edit'] = '<button type="submit" name="edit" id="edit" class="tl_submit" accesskey="s">' . $GLOBALS['TL_LANG']['MSC']['editSelected'] . '</button>';
                }

                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notDeletable'] ?? null))
                {
                    $arrButtons['delete'] = '<button type="submit" name="delete" id="delete" class="tl_submit" accesskey="d" onclick="return confirm(\'' . $GLOBALS['TL_LANG']['MSC']['delAllConfirm'] . '\')">' . $GLOBALS['TL_LANG']['MSC']['deleteSelected'] . '</button>';
                }

                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notCopyable'] ?? null))
                {
                    $arrButtons['copy'] = '<button type="submit" name="copy" id="copy" class="tl_submit" accesskey="c">' . $GLOBALS['TL_LANG']['MSC']['copySelected'] . '</button>';
                }

                if (!($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ?? null))
                {
                    $arrButtons['override'] = '<button type="submit" name="override" id="override" class="tl_submit" accesskey="v">' . $GLOBALS['TL_LANG']['MSC']['overrideSelected'] . '</button>';
                }

                // Call the buttons_callback (see #4691)
                if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['select']['buttons_callback'] ?? null))
                {
                    foreach ($GLOBALS['TL_DCA'][$this->strTable]['select']['buttons_callback'] as $callback)
                    {
                        if (\is_array($callback))
                        {
                            $arrButtons = System::importStatic($callback[0])->{$callback[1]}($arrButtons, $this);
                        }
                        elseif (\is_callable($callback))
                        {
                            $arrButtons = $callback($arrButtons, $this);
                        }
                    }
                }

                if (\count($arrButtons) < 3)
                {
                    $strButtons = implode(' ', $arrButtons);
                }
                else
                {
                    $strButtons = array_shift($arrButtons) . ' ';
                    $strButtons .= '<div class="split-button">';
                    $strButtons .= array_shift($arrButtons) . '<button type="button" id="sbtog">' . Image::getHtml('navcol.svg') . '</button> <ul class="invisible">';

                    foreach ($arrButtons as $strButton)
                    {
                        $strButtons .= '<li>' . $strButton . '</li>';
                    }

                    $strButtons .= '</ul></div>';
                }

                $return .= '
</div>
<div class="tl_formbody_submit" style="text-align:right">
<div class="tl_submit_container">
  ' . $strButtons . '
</div>
</div>
</form>';
            }
        }

        return $return;
    }

    public function cut($blnDoNotRedirect = false): void
    {
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notSortable'] ?? null)
        {
            throw new AccessDeniedException('Table "' . $this->strTable . '" is not sortable.');
        }

        $cr = array();

        // ID and PID are mandatory (PID can be 0!)
        if (!$this->intId || Input::get('pid') === null)
        {
            throw new NotFoundException('Cannot load record "' . $this->strTable . '.id=' . $this->intId . '".');
        }

        try
        {
            // Load current record before calculating new position etc. in case the user does not have read access
            $currentRecord = $this->getCurrentRecord();
        }
        catch (AccessDeniedException)
        {
            $currentRecord = null;
        }

        if ($currentRecord === null)
        {
            if (!$blnDoNotRedirect)
            {
                $this->redirect($this->getReferer());
            }

            return;
        }

        $db = Database::getInstance();

        // Get the new position
        $this->getNewPosition('cut', Input::get('pid'), Input::get('mode') == '2');

        // Avoid circular references when there is no parent table
        if (!$this->ptable && $db->fieldExists('pid', $this->strTable))
        {
            $cr = $db->getChildRecords($this->intId, $this->strTable);
            $cr[] = $this->intId;
        }

        /** @var Session $objSession */
        $objSession = System::getContainer()->get('request_stack')->getSession();

        // Empty clipboard
        $arrClipboard = $objSession->get('CLIPBOARD');
        $arrClipboard[$this->strTable] = array();
        $objSession->set('CLIPBOARD', $arrClipboard);

        // Check for circular references
        if (\in_array(($this->set['pid'] ?? null), $cr))
        {
            throw new UnprocessableEntityHttpException('Attempt to relate record ' . $this->intId . ' of table "' . $this->strTable . '" to its child record ' . Input::get('pid') . ' (circular reference).');
        }

        $this->set['tstamp'] = time();

        // Dynamically set the parent table of tl_content
        if ($GLOBALS['TL_DCA'][$this->strTable]['config']['dynamicPtable'] ?? null)
        {
            $this->set['ptable'] = $this->ptable;
        }

        $this->denyAccessUnlessGranted(ContaoCorePermissions::DC_PREFIX . $this->strTable, new UpdateAction($this->strTable, $currentRecord, $this->set));

        $db
            ->prepare("UPDATE " . $this->strTable . " %s WHERE id=?")
            ->set($this->set)
            ->execute($this->intId);

        // Call the oncut_callback
        if (\is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['oncut_callback'] ?? null))
        {
            foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['oncut_callback'] as $callback)
            {
                if (\is_array($callback))
                {
                    System::importStatic($callback[0])->{$callback[1]}($this);
                }
                elseif (\is_callable($callback))
                {
                    $callback($this);
                }
            }
        }

        if (!$blnDoNotRedirect)
        {
            $this->redirect($this->getReferer());
        }
    }

    protected function getNewPosition($mode, $pid = null, $insertInto = false): void
    {
        $db = Database::getInstance();

        // If there is sorting
        if ($db->fieldExists('sorting', $this->strTable))
        {
            // PID is not set - only valid for duplicated records, as they get the same parent ID as the original record!
            // TODO: check how records are copied (probably call parent::getNewPosition())
            if ($pid === null && $this->intId && $mode == 'copy')
            {
                $pid = $this->intId;
            }

            // PID is set (insert after or into the parent record)
            if (is_numeric($pid))
            {
                $newSorting = null;

                // Insert the current record after the parent record
                if ($pid > 0)
                {
                    $objSorting = $db
                        ->prepare("SELECT sorting FROM " . $this->strTable . " WHERE id=?")
                        ->limit(1)
                        ->execute($pid);

                    // Set parent ID of the current record as new parent ID
                    if ($objSorting->numRows)
                    {
                        $curSorting = $objSorting->sorting;

                        $objNextSorting = $db
                            ->prepare("SELECT MIN(sorting) AS sorting FROM " . $this->strTable . " WHERE sorting>?")
                            ->execute($curSorting);

                        // Select sorting value of the next record
                        if ($objNextSorting->sorting !== null)
                        {
                            $nxtSorting = $objNextSorting->sorting;

                            // Resort if the new sorting value is no integer or bigger than a MySQL integer
                            if ((($curSorting + $nxtSorting) % 2) != 0 || $nxtSorting >= 4294967295)
                            {
                                $count = 1;

                                $objNewSorting = $db
                                    ->prepare("SELECT id, sorting FROM " . $this->strTable . " ORDER BY sorting, id")
                                    ->execute();

                                while ($objNewSorting->next())
                                {
                                    $db
                                        ->prepare("UPDATE " . $this->strTable . " SET sorting=? WHERE id=?")
                                        ->execute($count++ * 128, $objNewSorting->id);

                                    if ($objNewSorting->sorting == $curSorting)
                                    {
                                        $newSorting = $count++ * 128;
                                    }
                                }
                            }

                            // Else new sorting = (current sorting + next sorting) / 2
                            else
                            {
                                $newSorting = ($curSorting + $nxtSorting) / 2;
                            }
                        }

                        // Else new sorting = (current sorting + 128)
                        else
                        {
                            $newSorting = $curSorting + 128;
                        }
                    }

                    // Use the given parent ID as parent ID
                    else
                    {
                        $newSorting = 128;
                    }
                }

                // Else insert the current record at the beginning
                else
                {
                    $objSorting = $db
                        ->prepare("SELECT MIN(sorting) AS sorting FROM " . $this->strTable)
                        ->execute();

                    // Select sorting value of the first record
                    if ($objSorting->numRows)
                    {
                        $curSorting = $objSorting->sorting;

                        // Resort if the new sorting value is not an integer or smaller than 1
                        // TODO: test the case
                        if (($curSorting % 2) != 0 || $curSorting < 1)
                        {
                            $objNewSorting = $db
                                ->prepare("SELECT id FROM " . $this->strTable . " ORDER BY sorting, id")
                                ->execute();

                            $count = 2;
                            $newSorting = 128;

                            while ($objNewSorting->next())
                            {
                                $db
                                    ->prepare("UPDATE " . $this->strTable . " SET sorting=? WHERE id=?")
                                    ->limit(1)
                                    ->execute($count++ * 128, $objNewSorting->id);
                            }
                        }

                        // Else new sorting = (current sorting / 2)
                        else
                        {
                            $newSorting = $curSorting / 2;
                        }
                    }

                    // Else new sorting = 128
                    else
                    {
                        $newSorting = 128;
                    }
                }

                // Set new sorting
                $this->set['sorting'] = (int) $newSorting;
            }
        }
    }
}
