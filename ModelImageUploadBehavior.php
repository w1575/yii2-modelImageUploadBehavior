<?php


namespace frontend\components\behaviors;


use Imagine\Image\Box;
use yii\db\ActiveRecord;
use yii\helpers\FileHelper;
use yii\imagine\Image;
use yii\web\UploadedFile;

class ModelImageUploadBehavior extends \yii\base\Behavior
{
    /**
     * @var string путь на основании которого будет генерироваться ссылка на изображение
     */
    public $webPath;
    /**
     * @var string путь до папки на стороне сервера. Например 'frontend/web/uploads'
     */
    public $folderAlias;
    /**
     * @var bool если true то будет сгенерировано произваольное имя для файла
     */
    public $generateName = false;
    /**
     * @var int длина генерируемого имени
     */
    public $generatedNameLength = 12;
    /**
     * @var int
     */
    public $previewHeight = 50;
    /**
     * @var int
     */
    public $previewWidth = 50;
    /**
     * @var int  настройки качества превьюшки
     */
    public $previewQuality = 90;
    /**
     * @var string префикс для генерируемой превью изображения
     */
    public $previewPrefix = '';
    /**
     * @var string|bool название директории, в которой будут храниться превьюшки. Можнно хранить в основной директории
     * однако для этого нужно будет указать параметр $previewPrefix, чтобы превью не заменило основную картинку.
     */
    public $previewSubFolder = '/previews';
    /**
     * @var string имя аттрибута, который указывает на то, какие изображения нужно удалить
     */
    public $deleteAttribute = '';
    /**
     * @var bool генерировать ли превью для файла
     */
    public $generatePreview = true;
    /**
     * @var array хранятся параметры аттриубута
     */
    private $attributeParams = [];

    /**
     * Настройки изображения. Пример
        [
            'logoAttribute' => [
                'dbAttribute' => 'logo_name', // обязательный аттриубт
                // индивидуальные настройки для каждого аттрибута здесь
                // если они не указаны то будут использованы глобальные
                'webPath' => '/uploads/logos',
                'folderAlias' => 'frontend/web/uploads/logos',
                'previewSubFolder' => false,
                'previewPrefix' => 'preview_',
                'generateName' => true,

            ],
            'anotherImgAttribute' => [
                'dbAttribute' => 'img', // обязательный аттрибут
                // если не нужны индивидуальные настройки

            ],
        ]
     *
     * @var array|string
     */
    public $attributesSettings = [];

    /**
     * @return array|string[]
     */
    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'getFilesInstances',
            ActiveRecord::EVENT_BEFORE_INSERT => 'uploadFiles',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'uploadFiles',
        ];
    }

    /**
     * Записыват instance загруженного файла в выбранные аттриубуты моделей, дабы они провалидировались
     */
    public function getFilesInstances()
    {
        foreach ($this->attributesSettings as $attributeName => $attributesSetting) {
            $this->owner->$attributeName = UploadedFile::getInstance($this->owner, $attributeName);
        }
    }

    /**
     * @throws \Exception
     */
    public function init()
    {
        $errorBegin = "Не указан обязательный атрибут: ";
//        if ($this->webPath === null) {
//            throw new \Exception("$errorBegin webPath.");
//        }
//
//        if ($this->folderAlias === null) {
//            throw new \Exception("$errorBegin folderAlias.");
//        }
//
//        if ($this->dbAttribute === null) {
//            throw new \Exception("Не указан обязательный атрибут dbAttribute.");
//        }

        foreach ($this->attributesSettings as $index => $attributesSetting) {
            if (isset($attributesSetting['dbAttribute']) === false) {
                throw new \Exception("$errorBegin dbAttribute");
            }
        }

    }

    /**
     * Если указаны индивидуальные настройки для атриубута, то то берутся они.
     * @param $settingName
     * @return mixed
     */
    private function getSetting($settingName)
    {
        return $this->attributeParams[$settingName] ?? $this->{$settingName};
    }

    /**
     * @return false
     */
    public function uploadFiles()
    {
        if ($this->owner->hasErrors() === true) {
            return false;
        }

        $a = $this->owner->hasErrors();

        foreach ($this->attributesSettings as $attributeName => $attributesSetting) {
            
            if ($this->owner->$attributeName === null) {
                continue;
            }

            $this->attributeParams = $attributesSetting;
            $folderPath = \yii::getAlias("@{$this->getSetting('folderAlias')}");
            $attribute =  $this->owner->{$attributeName};
            $fileName = (
                $this->getSetting('generateName') === true
                ? \yii::$app->security->generateRandomString($this->generatedNameLength)
                : $this->owner->{$attributeName}->baseName
                )
                . ".{$this->owner->{$attributeName}->extension}"
            ;
            if (is_dir($folderPath) == false) {
                $old = umask(0);
                \mkdir($folderPath, 0775, true);
                umask($old);
            }
            $fullPath = "{$folderPath}/{$fileName}";
            $old = umask(0);
            $this->owner->$attributeName->saveAs($fullPath);
            umask($old);
            $generatePreview = $this->getSetting('generatePreview');

            if ($generatePreview === true) {
                $subFolder = $this->getSetting('previewSubFolder');
                $previewFolder =
                    (
                        empty($subFolder)
                        ? $folderPath
                        : $folderPath . "/" .$subFolder
                    ) . "/" ;
                if (is_dir($previewFolder) === false) {
                    $old = umask(0);
                    mkdir($previewFolder, 0775, true);
                    umask($old);
                }
                $previewPath = $previewFolder . $this->getSetting('previewPrefix') . $fileName;
                $height = $this->getSetting('previewHeight');
                $width = $this->getSetting('previewWidth');
                try {
                    $old = umask(0);
                    Image::thumbnail($fullPath, $width, $height)
                        ->save(
                            $previewPath,
                            [
                                'quality' => $this->getSetting('previewQuality')
                            ]
                        );
                    umask($old);
                } catch (\Exception $e) {
                    $this->owner->addError($attributeName, $e->getMessage());
                    return false;
                }
                $this->owner->{$attributesSetting['dbAttribute']} = $fileName;
            }
        }
    }

    /**
     * @param $attributeName
     */
    private function prepareAttributeSettings($attributeName)
    {
        $this->attributeParams = $this->attributesSettings[$attributeName];
    }

    /**
     * Возвращает ссылку на превью изображения
     * @param $attributeName
     */
    public function getPreviewLink($attributeName)
    {
        $this->prepareAttributeSettings($attributeName);
        $path = $this->getSetting('webPath');
        $subFolder = $this->getSetting('previewSubFolder');
        $path .= (
            empty($subFolder)
            ? "/"
            : "/{$subFolder}/"
        );
        $prefix = $this->getSetting('previewPrefix');
        $path .= (
            empty($prefix)
            ? ''
            : "$prefix"
        ) . $this->owner->{$this->getSetting('dbAttribute')}
        ;

        return $path;


    }

    /**
     * @param $attributeName
     */
    public function getFullLink($attributeName)
    {
        $this->prepareAttributeSettings($attributeName);
        $path = $this->getSetting('webPath');
        return "{$path}/" . $this->owner->{$this->getSetting('dbAttribute')};
    }




}