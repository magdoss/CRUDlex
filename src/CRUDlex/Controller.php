<?php

/*
 * This file is part of the CRUDlex package.
 *
 * (c) Philip Lehmann-Böhm <philip@philiplb.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CRUDlex;

use League\Flysystem\Util\MimeType;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;


/**
 * This is the Controller offering all CRUD pages.
 *
 * It offers functions for this routes:
 *
 * "/resource/static" serving static resources
 *
 * "/{entity}/create" creation page of the entity
 *
 * "/{entity}" list page of the entity
 *
 * "/{entity}/{id}" details page of a single entity instance
 *
 * "/{entity}/{id}/edit" edit page of a single entity instance
 *
 * "/{entity}/{id}/delete" POST only deletion route for an entity instance
 *
 * "/{entity}/{id}/{field}/file" renders a file field of an entity instance
 *
 * "/{entity}/{id}/{field}/delete" POST only deletion of a file field of an entity instance
 */
class Controller {

    /**
     * Postprocesses the entity after modification by handling the uploaded
     * files and setting the flash.
     *
     * @param Request $request
     * the current request
     * @param Application $app
     * the current application
     * @param AbstractData $crudData
     * the data instance of the entity
     * @param Entity $instance
     * the entity
     * @param string $entity
     * the name of the entity
     * @param string $mode
     * whether to 'edit' or to 'create' the entity
     *
     * @return null|\Symfony\Component\HttpFoundation\RedirectResponse
     * the HTTP response of this modification
     */
    protected function modifyFilesAndSetFlashBag(Request $request, Application $app, AbstractData $crudData, Entity $instance, $entity, $mode)
    {
        $id          = $instance->get('id');
        $fileHandler = new FileHandler($app['crud.filesystem'], $crudData->getDefinition());
        $result      = $mode == 'edit' ? $fileHandler->updateFiles($crudData, $request, $instance, $entity) : $fileHandler->createFiles($crudData, $request, $instance, $entity);
        if (!$result) {
            return null;
        }
        $app['session']->getFlashBag()->add('success', $app['translator']->trans('crudlex.'.$mode.'.success', [
            '%label%' => $crudData->getDefinition()->getLabel(),
            '%id%' => $id
        ]));
        return $app->redirect($app['url_generator']->generate('crudShow', ['entity' => $entity, 'id' => $id]));
    }

    /**
     * Sets the flashes of a failed entity modification.
     *
     * @param Application $app
     * the current application
     * @param boolean $optimisticLocking
     * whether the optimistic locking failed
     * @param string $mode
     * the modification mode, either 'create' or 'edit'
     */
    protected function setValidationFailedFlashes(Application $app, $optimisticLocking, $mode)
    {
        $app['session']->getFlashBag()->add('danger', $app['translator']->trans('crudlex.'.$mode.'.error'));
        if ($optimisticLocking) {
            $app['session']->getFlashBag()->add('danger', $app['translator']->trans('crudlex.edit.locked'));
        }
    }

    /**
     * Validates and saves the new or updated entity and returns the appropriate HTTP
     * response.
     *
     * @param Request $request
     * the current request
     * @param Application $app
     * the current application
     * @param AbstractData $crudData
     * the data instance of the entity
     * @param Entity $instance
     * the entity
     * @param string $entity
     * the name of the entity
     * @param boolean $edit
     * whether to edit (true) or to create (false) the entity
     *
     * @return Response
     * the HTTP response of this modification
     */
    protected function modifyEntity(Request $request, Application $app, AbstractData $crudData, Entity $instance, $entity, $edit)
    {
        $fieldErrors = [];
        $mode        = $edit ? 'edit' : 'create';
        if ($request->getMethod() == 'POST') {
            $instance->populateViaRequest($request);
            $validator  = new EntityValidator($instance);
            $validation = $validator->validate($crudData, intval($request->get('version')));

            $fieldErrors = $validation['errors'];
            if (!$validation['valid']) {
                $optimisticLocking = isset($fieldErrors['version']);
                $this->setValidationFailedFlashes($app, $optimisticLocking, $mode);
            } else {
                $modified = $edit ? $crudData->update($instance) : $crudData->create($instance);
                $response = $modified ? $this->modifyFilesAndSetFlashBag($request, $app, $crudData, $instance, $entity, $mode) : false;
                if ($response) {
                    return $response;
                }
                $app['session']->getFlashBag()->add('danger', $app['translator']->trans('crudlex.'.$mode.'.failed'));
            }
        }

        return $app['twig']->render($app['crud']->getTemplate('template', 'form', $entity), [
            'crud' => $app['crud'],
            'crudEntity' => $entity,
            'crudData' => $crudData,
            'entity' => $instance,
            'mode' => $mode,
            'fieldErrors' => $fieldErrors,
            'layout' => $app['crud']->getTemplate('layout', $mode, $entity)
        ]);
    }

    /**
     * Gets the parameters for the redirection after deleting an entity.
     *
     * @param Request $request
     * the current request
     * @param string $entity
     * the entity name
     * @param string $redirectPage
     * reference, where the page to redirect to will be stored
     *
     * @return array<string,string>
     * the parameters of the redirection, entity and id
     */
    protected function getAfterDeleteRedirectParameters(Request $request, $entity, &$redirectPage)
    {
        $redirectPage       = 'crudList';
        $redirectParameters = ['entity' => $entity];
        $redirectEntity     = $request->get('redirectEntity');
        $redirectId         = $request->get('redirectId');
        if ($redirectEntity && $redirectId) {
            $redirectPage       = 'crudShow';
            $redirectParameters = [
                'entity' => $redirectEntity,
                'id' => $redirectId
            ];
        }
        return $redirectParameters;
    }

    /**
     * Builds up the parameters of the list page filters.
     *
     * @param Request $request
     * the current application
     * @param EntityDefinition $definition
     * the current entity definition
     * @param array &$filter
     * will hold a map of fields to request parameters for the filters
     * @param boolean $filterActive
     * reference, will be true if at least one filter is active
     * @param array $filterToUse
     * reference, will hold a map of fields to integers (0 or 1) which boolean filters are active
     * @param array $filterOperators
     * reference, will hold a map of fields to operators for AbstractData::listEntries()
     */
    protected function buildUpListFilter(Request $request, EntityDefinition $definition, &$filter, &$filterActive, &$filterToUse, &$filterOperators)
    {
        foreach ($definition->getFilter() as $filterField) {
            $type                 = $definition->getType($filterField);
            $filter[$filterField] = $request->get('crudFilter'.$filterField);
            if ($filter[$filterField]) {
                $filterActive                  = true;
                $filterToUse[$filterField]     = $filter[$filterField];
                $filterOperators[$filterField] = '=';
                if ($type === 'boolean') {
                    $filterToUse[$filterField] = $filter[$filterField] == 'true' ? 1 : 0;
                } else if ($type === 'reference') {
                    $filter[$filterField] = ['id' => $filter[$filterField]];
                } else if ($type === 'many') {
                    $filter[$filterField] = array_map(function($value) {
                        return ['id' => $value];
                    }, $filter[$filterField]);
                    $filterToUse[$filterField] = $filter[$filterField];
                } else if (in_array($type, ['text', 'multiline', 'fixed'])) {
                    $filterToUse[$filterField]     = '%'.$filter[$filterField].'%';
                    $filterOperators[$filterField] = 'LIKE';
                }
            }
        }
    }

    /**
     * Generates the not found page.
     *
     * @param Application $app
     * the Silex application
     * @param string $error
     * the cause of the not found error
     *
     * @return Response
     * the rendered not found page with the status code 404
     */
    public function getNotFoundPage(Application $app, $error)
    {
        return new Response($app['twig']->render('@crud/notFound.twig', [
            'crud' => $app['crud'],
            'error' => $error,
            'crudEntity' => '',
            'layout' => $app['crud']->getTemplate('layout', '', '')
        ]), 404);
    }

    /**
     * Transfers the locale from the translator to CRUDlex and
     *
     * @param Request $request
     * the current request
     * @param Application $app
     * the Silex application
     * @return Response|null
     * null if everything is ok, a 404 response else
     */
    public function setLocaleAndCheckEntity(Request $request, Application $app)
    {
        $locale = $app['translator']->getLocale();
        $app['crud']->setLocale($locale);
        if (!$app['crud']->getData($request->get('entity'))) {
            return $this->getNotFoundPage($app, $app['translator']->trans('crudlex.entityNotFound'));
        }
        return null;
    }

    /**
     * The controller for the "create" action.
     *
     * @param Request $request
     * the current request
     * @param Application $app
     * the Silex application
     * @param string $entity
     * the current entity
     *
     * @return Response
     * the HTTP response of this action
     */
    public function create(Request $request, Application $app, $entity)
    {
        $crudData = $app['crud']->getData($entity);
        $instance = $crudData->createEmpty();
        $instance->populateViaRequest($request);
        return $this->modifyEntity($request, $app, $crudData, $instance, $entity, false);
    }

    /**
     * The controller for the "show list" action.
     *
     * @param Request $request
     * the current request
     * @param Application $app
     * the Silex application
     * @param string $entity
     * the current entity
     *
     * @return Response
     * the HTTP response of this action or 404 on invalid input
     */
    public function showList(Request $request, Application $app, $entity)
    {
        $crudData   = $app['crud']->getData($entity);
        $definition = $crudData->getDefinition();

        $filter          = [];
        $filterActive    = false;
        $filterToUse     = [];
        $filterOperators = [];
        $this->buildUpListFilter($request, $definition, $filter, $filterActive, $filterToUse, $filterOperators);

        $pageSize = $definition->getPageSize();
        $total    = $crudData->countBy($definition->getTable(), $filterToUse, $filterOperators, true);
        $page     = abs(intval($request->get('crudPage', 0)));
        $maxPage  = intval($total / $pageSize);
        if ($total % $pageSize == 0) {
            $maxPage--;
        }
        if ($page > $maxPage) {
            $page = $maxPage;
        }
        $skip = $page * $pageSize;

        $sortField            = $request->get('crudSortField', $definition->getInitialSortField());
        $sortAscendingRequest = $request->get('crudSortAscending');
        $sortAscending        = $sortAscendingRequest !== null ? $sortAscendingRequest === 'true' : $definition->isInitialSortAscending();

        $entities = $crudData->listEntries($filterToUse, $filterOperators, $skip, $pageSize, $sortField, $sortAscending);

        return $app['twig']->render($app['crud']->getTemplate('template', 'list', $entity), [
            'crud' => $app['crud'],
            'crudEntity' => $entity,
            'crudData' => $crudData,
            'definition' => $definition,
            'entities' => $entities,
            'pageSize' => $pageSize,
            'maxPage' => $maxPage,
            'page' => $page,
            'total' => $total,
            'filter' => $filter,
            'filterActive' => $filterActive,
            'sortField' => $sortField,
            'sortAscending' => $sortAscending,
            'layout' => $app['crud']->getTemplate('layout', 'list', $entity)
        ]);
    }

    /**
     * The controller for the "show" action.
     *
     * @param Application $app
     * the Silex application
     * @param string $entity
     * the current entity
     * @param string $id
     * the instance id to show
     *
     * @return Response
     * the HTTP response of this action or 404 on invalid input
     */
    public function show(Application $app, $entity, $id)
    {
        $crudData = $app['crud']->getData($entity);
        $instance = $crudData->get($id);
        if (!$instance) {
            return $this->getNotFoundPage($app, $app['translator']->trans('crudlex.instanceNotFound'));
        }
        $definition = $crudData->getDefinition();

        $childrenLabelFields = $definition->getChildrenLabelFields();
        $children            = [];
        if (count($childrenLabelFields) > 0) {
            foreach ($definition->getChildren() as $child) {
                $childField      = $child[1];
                $childEntity     = $child[2];
                $childLabelField = array_key_exists($childEntity, $childrenLabelFields) ? $childrenLabelFields[$childEntity] : 'id';
                $childCrud       = $app['crud']->getData($childEntity);
                $children[]      = [
                    $childCrud->getDefinition()->getLabel(),
                    $childEntity,
                    $childLabelField,
                    $childCrud->listEntries([$childField => $instance->get('id')]),
                    $childField
                ];
            }
        }

        return $app['twig']->render($app['crud']->getTemplate('template', 'show', $entity), [
            'crud' => $app['crud'],
            'crudEntity' => $entity,
            'entity' => $instance,
            'children' => $children,
            'layout' => $app['crud']->getTemplate('layout', 'show', $entity)
        ]);
    }

    /**
     * The controller for the "edit" action.
     *
     * @param Request $request
     * the current request
     * @param Application $app
     * the Silex application
     * @param string $entity
     * the current entity
     * @param string $id
     * the instance id to edit
     *
     * @return Response
     * the HTTP response of this action or 404 on invalid input
     */
    public function edit(Request $request, Application $app, $entity, $id)
    {
        $crudData = $app['crud']->getData($entity);
        $instance = $crudData->get($id);
        if (!$instance) {
            return $this->getNotFoundPage($app, $app['translator']->trans('crudlex.instanceNotFound'));
        }

        return $this->modifyEntity($request, $app, $crudData, $instance, $entity, true);
    }

    /**
     * The controller for the "delete" action.
     *
     * @param Request $request
     * the current request
     * @param Application $app
     * the Silex application
     * @param string $entity
     * the current entity
     * @param string $id
     * the instance id to delete
     *
     * @return Response
     * redirects to the entity list page or 404 on invalid input
     */
    public function delete(Request $request, Application $app, $entity, $id)
    {
        $crudData = $app['crud']->getData($entity);
        $instance = $crudData->get($id);
        if (!$instance) {
            return $this->getNotFoundPage($app, $app['translator']->trans('crudlex.instanceNotFound'));
        }

        $fileHandler  = new FileHandler($app['crud.filesystem'], $crudData->getDefinition());
        $filesDeleted = $fileHandler->deleteFiles($crudData, $instance, $entity);
        $deleted      = $filesDeleted ? $crudData->delete($instance) : AbstractData::DELETION_FAILED_EVENT;

        if ($deleted === AbstractData::DELETION_FAILED_EVENT) {
            $app['session']->getFlashBag()->add('danger', $app['translator']->trans('crudlex.delete.failed'));
            return $app->redirect($app['url_generator']->generate('crudShow', ['entity' => $entity, 'id' => $id]));
        } elseif ($deleted === AbstractData::DELETION_FAILED_STILL_REFERENCED) {
            $app['session']->getFlashBag()->add('danger', $app['translator']->trans('crudlex.delete.error', [
                '%label%' => $crudData->getDefinition()->getLabel()
            ]));
            return $app->redirect($app['url_generator']->generate('crudShow', ['entity' => $entity, 'id' => $id]));
        }

        $redirectPage       = 'crudList';
        $redirectParameters = $this->getAfterDeleteRedirectParameters($request, $entity, $redirectPage);

        $app['session']->getFlashBag()->add('success', $app['translator']->trans('crudlex.delete.success', [
            '%label%' => $crudData->getDefinition()->getLabel()
        ]));
        return $app->redirect($app['url_generator']->generate($redirectPage, $redirectParameters));
    }

    /**
     * The controller for the "render file" action.
     *
     * @param Application $app
     * the Silex application
     * @param string $entity
     * the current entity
     * @param string $id
     * the instance id
     * @param string $field
     * the field of the file to render of the instance
     *
     * @return Response
     * the rendered file
     */
    public function renderFile(Application $app, $entity, $id, $field)
    {
        $crudData   = $app['crud']->getData($entity);
        $instance   = $crudData->get($id);
        $definition = $crudData->getDefinition();
        if (!$instance || $definition->getType($field) != 'file' || !$instance->get($field)) {
            return $this->getNotFoundPage($app, $app['translator']->trans('crudlex.instanceNotFound'));
        }
        $fileHandler = new FileHandler($app['crud.filesystem'], $definition);
        return $fileHandler->renderFile($instance, $entity, $field);
    }

    /**
     * The controller for the "delete file" action.
     *
     * @param Application $app
     * the Silex application
     * @param string $entity
     * the current entity
     * @param string $id
     * the instance id
     * @param string $field
     * the field of the file to delete of the instance
     *
     * @return Response
     * redirects to the instance details page or 404 on invalid input
     */
    public function deleteFile(Application $app, $entity, $id, $field)
    {
        $crudData = $app['crud']->getData($entity);
        $instance = $crudData->get($id);
        if (!$instance) {
            return $this->getNotFoundPage($app, $app['translator']->trans('crudlex.instanceNotFound'));
        }
        $fileHandler = new FileHandler($app['crud.filesystem'], $crudData->getDefinition());
        if (!$crudData->getDefinition()->getField($field, 'required', false) && $fileHandler->deleteFile($crudData, $instance, $entity, $field)) {
            $instance->set($field, '');
            $crudData->update($instance);
            $app['session']->getFlashBag()->add('success', $app['translator']->trans('crudlex.file.deleted'));
        } else {
            $app['session']->getFlashBag()->add('danger', $app['translator']->trans('crudlex.file.notDeleted'));
        }
        return $app->redirect($app['url_generator']->generate('crudShow', ['entity' => $entity, 'id' => $id]));
    }

    /**
     * The controller for serving static files.
     *
     * @param Request $request
     * the current request
     * @param Application $app
     * the Silex application
     *
     * @return Response
     * redirects to the instance details page or 404 on invalid input
     */
    public function staticFile(Request $request, Application $app)
    {
        $fileParam = str_replace('..', '', $request->get('file'));
        $file      = __DIR__.'/../static/'.$fileParam;
        if (!$fileParam || !file_exists($file)) {
            return $this->getNotFoundPage($app, $app['translator']->trans('crudlex.resourceNotFound'));
        }

        $mimeType = MimeType::detectByFilename($file);
        $size     = filesize($file);

        $streamedFileResponse = new StreamedFileResponse();
        $response             = new StreamedResponse($streamedFileResponse->getStreamedFileFunction($file), 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'attachment; filename="'.basename($file).'"',
            'Content-length' => $size
        ]);

        $response->setETag(filemtime($file))->setPublic()->isNotModified($request);
        $response->send();

        return $response;
    }

    /**
     * The controller for setting the locale.
     *
     * @param Request $request
     * the current request
     * @param Application $app
     * the Silex application
     * @param string $locale
     * the new locale
     *
     * @return Response
     * redirects to the instance details page or 404 on invalid input
     */
    public function setLocale(Request $request, Application $app, $locale)
    {

        if (!in_array($locale, $app['crud']->getLocales())) {
            return $this->getNotFoundPage($app, 'Locale '.$locale.' not found.');
        }

        $manageI18n = $app['crud']->isManageI18n();
        if ($manageI18n) {
            $app['session']->set('locale', $locale);
        }
        $redirect = $request->get('redirect');
        return $app->redirect($redirect);
    }
}