<?php

/**
 * @file controllers/grid/articleGalleys/ArticleGalleyGridHandler.inc.php
 *
 * Copyright (c) 2016-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleGalleyGridHandler
 * @ingroup controllers_grid_articleGalleys
 *
 * @brief Handle article galley grid requests.
 */

use APP\facades\Repo;
use APP\notification\NotificationManager;
use APP\template\TemplateManager;
use PKP\controllers\grid\GridColumn;
use PKP\controllers\grid\GridHandler;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\notification\PKPNotification;

use PKP\security\Role;
use PKP\submission\PKPSubmission;

class ArticleGalleyGridHandler extends GridHandler
{
    /** @var PKPRequest */
    public $_request;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->addRoleAssignment(
            [Role::ROLE_ID_AUTHOR, Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT],
            ['fetchGrid', 'fetchRow']
        );
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT],
            ['addGalley', 'editGalley', 'editGalleyTab', 'updateGalley', 'deleteGalley', 'identifiers', 'updateIdentifiers', 'clearPubId', 'saveSequence']
        );
    }


    //
    // Getters/Setters
    //
    /**
     * Get the authorized submission.
     *
     * @return Submission
     */
    public function getSubmission()
    {
        return $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
    }

    /**
     * Get the authorized publication.
     *
     * @return Publication
     */
    public function getPublication()
    {
        return $this->getAuthorizedContextObject(ASSOC_TYPE_PUBLICATION);
    }

    /**
     * Get the authorized galley.
     *
     * @return ArticleGalley
     */
    public function getGalley()
    {
        return $this->getAuthorizedContextObject(ASSOC_TYPE_REPRESENTATION);
    }


    //
    // Overridden methods from PKPHandler.
    //
    /**
     * @see GridHandler::getJSHandler()
     */
    public function getJSHandler()
    {
        return '$.pkp.controllers.grid.articleGalleys.ArticleGalleyGridHandler';
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->_request = $request;

        import('lib.pkp.classes.security.authorization.WorkflowStageAccessPolicy');
        $this->addPolicy(new WorkflowStageAccessPolicy($request, $args, $roleAssignments, 'submissionId', WORKFLOW_STAGE_ID_PRODUCTION));

        import('lib.pkp.classes.security.authorization.PublicationAccessPolicy');
        $this->addPolicy(new PublicationAccessPolicy($request, $args, $roleAssignments));

        if ($request->getUserVar('representationId')) {
            import('lib.pkp.classes.security.authorization.internal.RepresentationRequiredPolicy');
            $this->addPolicy(new RepresentationRequiredPolicy($request, $args));
        }

        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * @copydoc GridHandler::initialize()
     *
     * @param null|mixed $args
     */
    public function initialize($request, $args = null)
    {
        parent::initialize($request, $args);
        $this->setTitle('submission.layout.galleys');

        // Load pkp-lib translations
        AppLocale::requireComponents(
            LOCALE_COMPONENT_PKP_SUBMISSION,
            LOCALE_COMPONENT_PKP_USER,
            LOCALE_COMPONENT_PKP_EDITOR,
            LOCALE_COMPONENT_APP_EDITOR
        );

        import('controllers.grid.articleGalleys.ArticleGalleyGridCellProvider');
        $cellProvider = new ArticleGalleyGridCellProvider($this->getSubmission(), $this->getPublication(), $this->canEdit());

        // Columns
        $this->addColumn(new GridColumn(
            'label',
            'common.name',
            null,
            null,
            $cellProvider
        ));

        if ($this->canEdit()) {
            $this->addAction(new LinkAction(
                'addGalley',
                new AjaxModal(
                    $request->getRouter()->url($request, null, null, 'addGalley', null, $this->getRequestArgs()),
                    __('submission.layout.newGalley'),
                    'modal_add_item'
                ),
                __('grid.action.addGalley'),
                'add_item'
            ));
        }
    }

    //
    // Overridden methods from GridHandler
    //
    /**
     * @copydoc GridHandler::initFeatures()
     */
    public function initFeatures($request, $args)
    {
        if ($this->canEdit()) {
            import('lib.pkp.classes.controllers.grid.feature.OrderGridItemsFeature');
            return [new OrderGridItemsFeature()];
        }

        return [];
    }

    /**
     * @copydoc GridHandler::getDataElementSequence()
     */
    public function getDataElementSequence($row)
    {
        return $row->getSequence();
    }

    /**
     * @copydoc GridHandler::setDataElementSequence()
     */
    public function setDataElementSequence($request, $rowId, $gridDataElement, $newSequence)
    {
        $galley = Repo::articleGalley()->get((int) $rowId);
        $galley->setData('seq', $newSequence);
        Repo::articleGalley()->dao->update($galley);
    }

    //
    // Overridden methods from GridHandler
    //
    /**
     * @copydoc GridHandler::getRowInstance()
     *
     * @return ArticleGalleyGridRow
     */
    public function getRowInstance()
    {
        import('controllers.grid.articleGalleys.ArticleGalleyGridRow');
        return new ArticleGalleyGridRow(
            $this->getSubmission(),
            $this->getPublication(),
            $this->canEdit()
        );
    }

    /**
     * Get the arguments that will identify the data in the grid.
     * Overridden by child grids.
     *
     * @return array
     */
    public function getRequestArgs()
    {
        return [
            'submissionId' => $this->getSubmission()->getId(),
            'publicationId' => $this->getPublication()->getId(),
        ];
    }

    /**
     * @copydoc GridHandler::loadData()
     *
     * @param null|mixed $filter
     */
    public function loadData($request, $filter = null)
    {
        $collector = Repo::articleGalley()->getCollector();
        $collector->filterByPublicationIds([$this->getPublication()->getId()]);
        $galleyIterator = Repo::articleGalley()->getMany($collector);
        $galleys = [];
        foreach ($galleyIterator as $galley) {
            $galleys[$galley->getId()] = $galley;
        }
        return $galleys;
    }

    //
    // Public Galley Grid Actions
    //
    /**
     * Edit article galley pub ids
     *
     * @param $args array
     * @param $request PKPRequest
     *
     * @return JSONMessage JSON object
     */
    public function identifiers($args, $request)
    {
        $representation = Repo::articleGalley()->get($request->getUserVar('representationId'));
        import('controllers.tab.pubIds.form.PublicIdentifiersForm');
        $form = new PublicIdentifiersForm($representation);
        $form->initData();
        return new JSONMessage(true, $form->fetch($request));
    }

    /**
     * Update article galley pub ids
     *
     * @param $args array
     * @param $request PKPRequest
     *
     * @return JSONMessage JSON object
     */
    public function updateIdentifiers($args, $request)
    {
        $representation = Repo::articleGalley()->get($request->getUserVar('representationId'));
        import('controllers.tab.pubIds.form.PublicIdentifiersForm');
        $form = new PublicIdentifiersForm($representation, null, array_merge($this->getRequestArgs(), ['representationId' => $representation->getId()]));
        $form->readInputData();
        if ($form->validate()) {
            $form->execute();
            return DAO::getDataChangedEvent();
        } else {
            return new JSONMessage(true, $form->fetch($request));
        }
    }

    /**
     * Clear galley pub id
     *
     * @param $args array
     * @param $request PKPRequest
     *
     * @return JSONMessage JSON object
     */
    public function clearPubId($args, $request)
    {
        if (!$request->checkCSRF()) {
            return new JSONMessage(false);
        }

        $submission = $this->getSubmission();
        $representation = Repo::articleGalley()->get($request->getUserVar('representationId'));
        import('controllers.tab.pubIds.form.PublicIdentifiersForm');
        $form = new PublicIdentifiersForm($representation);
        $form->clearPubId($request->getUserVar('pubIdPlugIn'));
        return new JSONMessage(true);
    }

    /**
     * Add a galley
     *
     * @param $args array
     * @param $request PKPRequest
     *
     * @return JSONMessage JSON object
     */
    public function addGalley($args, $request)
    {
        import('controllers.grid.articleGalleys.form.ArticleGalleyForm');
        $galleyForm = new ArticleGalleyForm(
            $request,
            $this->getSubmission(),
            $this->getPublication()
        );
        $galleyForm->initData();
        return new JSONMessage(true, $galleyForm->fetch($request));
    }

    /**
     * Delete a galley.
     *
     * @param $args array
     * @param $request PKPRequest
     *
     * @return JSONMessage JSON object
     */
    public function deleteGalley($args, $request)
    {
        /** @var ArticleGalley $galley */
        $galley = $this->getGalley();
        if (!$galley || !$request->checkCSRF()) {
            return new JSONMessage(false);
        }
        Repo::articleGalley()->delete($galley);

        $notificationDao = DAORegistry::getDAO('NotificationDAO'); /* @var $notificationDao NotificationDAO */
        $notificationDao->deleteByAssoc(ASSOC_TYPE_REPRESENTATION, $galley->getId());

        if ($this->getSubmission()->getStageId() == WORKFLOW_STAGE_ID_EDITING ||
            $this->getSubmission()->getStageId() == WORKFLOW_STAGE_ID_PRODUCTION) {
            $notificationMgr = new NotificationManager();
            $notificationMgr->updateNotification(
                $request,
                [PKPNotification::NOTIFICATION_TYPE_ASSIGN_PRODUCTIONUSER, PKPNotification::NOTIFICATION_TYPE_AWAITING_REPRESENTATIONS],
                null,
                ASSOC_TYPE_SUBMISSION,
                $this->getSubmission()->getId()
            );
        }

        return DAO::getDataChangedEvent($galley->getId());
    }

    /**
     * Edit a galley metadata modal
     *
     * @param $args array
     * @param $request PKPRequest
     *
     * @return JSONMessage JSON object
     */
    public function editGalley($args, $request)
    {
        $galley = $this->getGalley();

        // Check if this is a remote galley
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'submissionId' => $this->getSubmission()->getId(),
            'publicationId' => $this->getPublication()->getId(),
            'representationId' => $galley->getId(),
        ]);
        $publisherIdEnabled = in_array('galley', (array) $request->getContext()->getData('enablePublisherId'));
        $pubIdsEnabled = false;
        $pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $request->getContext()->getId());
        foreach ($pubIdPlugins as $pubIdPlugin) {
            if ($pubIdPlugin->isObjectTypeEnabled('Representation', $request->getContext()->getId())) {
                $pubIdsEnabled = true;
                break;
            }
        }
        if ($publisherIdEnabled || $pubIdsEnabled) {
            $templateMgr->assign('enableIdentifiers', true);
        }
        return new JSONMessage(true, $templateMgr->fetch('controllers/grid/articleGalleys/editFormat.tpl'));
    }

    /**
     * Edit a galley
     *
     * @param $args array
     * @param $request PKPRequest
     *
     * @return JSONMessage JSON object
     */
    public function editGalleyTab($args, $request)
    {
        // Form handling
        import('controllers.grid.articleGalleys.form.ArticleGalleyForm');
        $galleyForm = new ArticleGalleyForm(
            $request,
            $this->getSubmission(),
            $this->getPublication(),
            $this->getGalley()
        );
        $galleyForm->initData();
        return new JSONMessage(true, $galleyForm->fetch($request));
    }

    /**
     * Save a galley
     *
     * @param $args array
     * @param $request PKPRequest
     *
     * @return JSONMessage JSON object
     */
    public function updateGalley($args, $request)
    {
        $galley = $this->getGalley();

        import('controllers.grid.articleGalleys.form.ArticleGalleyForm');
        $galleyForm = new ArticleGalleyForm($request, $this->getSubmission(), $this->getPublication(), $galley);
        $galleyForm->readInputData();

        if ($galleyForm->validate()) {
            $galley = $galleyForm->execute();

            if ($this->getSubmission()->getStageId() == WORKFLOW_STAGE_ID_EDITING ||
                $this->getSubmission()->getStageId() == WORKFLOW_STAGE_ID_PRODUCTION) {
                $notificationMgr = new NotificationManager();
                $notificationMgr->updateNotification(
                    $request,
                    [PKPNotification::NOTIFICATION_TYPE_ASSIGN_PRODUCTIONUSER, PKPNotification::NOTIFICATION_TYPE_AWAITING_REPRESENTATIONS],
                    null,
                    ASSOC_TYPE_SUBMISSION,
                    $this->getSubmission()->getId()
                );
            }

            return DAO::getDataChangedEvent($galley->getId());
        }
        return new JSONMessage(true, $galleyForm->fetch($request));
    }

    /**
     * @copydoc GridHandler::fetchRow()
     */
    public function fetchRow($args, $request)
    {
        $json = parent::fetchRow($args, $request);
        if ($row = $this->getRequestedRow($request, $args)) {
            $galley = $row->getData();
            if ($galley->getRemoteUrl() == '' && !$galley->getData('submissionFileId')) {
                $json->setEvent('uploadFile', $galley->getId());
            }
        }

        return $json;
    }

    /**
     * Can the current user edit the galleys in this grid?
     *
     * The user must have an allowed role in one of the assigned stages.
     * If the user is not assigned, they can edit if they are an editor
     * or admin.
     *
     * @return boolean
     */
    public function canEdit()
    {
        return $this->getPublication()->getData('status') !== PKPSubmission::STATUS_PUBLISHED &&
            Repo::user()->canUserAccessStage(
                WORKFLOW_STAGE_ID_PRODUCTION,
                PKPApplication::WORKFLOW_TYPE_EDITORIAL,
                $this->getAuthorizedContextObject(ASSOC_TYPE_ACCESSIBLE_WORKFLOW_STAGES),
                $this->getAuthorizedContextObject(ASSOC_TYPE_USER_ROLES)
            );
    }
}
