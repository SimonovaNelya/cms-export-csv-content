<?php
/**
 * @author Semenov Alexander <semenov@skeeks.com>
 * @link http://skeeks.com/
 * @copyright 2010 SkeekS (СкикС)
 * @date 29.08.2016
 */
namespace skeeks\cms\exportCsvContent;

use skeeks\cms\export\ExportHandler;
use skeeks\cms\export\ExportHandlerFilePath;
use skeeks\cms\importCsv\handlers\CsvHandler;
use skeeks\cms\importCsv\helpers\CsvImportRowResult;
use skeeks\cms\importCsv\ImportCsvHandler;
use skeeks\cms\importCsvContent\widgets\MatchingInput;
use skeeks\cms\models\CmsContent;
use skeeks\cms\models\CmsContentElement;
use skeeks\cms\models\Tree;
use skeeks\cms\models\CmsContentPropertyEnum;
use \v3toys\skeeks\models\V3toysProductContentElement;
use skeeks\cms\relatedProperties;
use yii\base\Exception;
use yii\helpers\ArrayHelper;
use yii\helpers\FileHelper;
use yii\helpers\Json;
use yii\widgets\ActiveForm;
use yii\data\Pagination;


/**
 * @property CmsContent $cmsContent
 *
 * Class CsvContentHandler
 *
 * @package skeeks\cms\importCsvContent
 */
class ExportCsvContentHandler extends ExportHandler
{
    public $content_id = null;

    public $file_path = '';

    /**
     * @var int
     */
    public $max_urlsets = 20;
    public $mem_start = null;



    const CSV_CHARSET_UTF8           = 'UTF-8';             //другой
    const CSV_CHARSET_WINDOWS1251    = 'windows-1251';             //другой

    /**
     * @var string
     */
    public $charset = self::CSV_CHARSET_UTF8;


    /**
     * Доступные кодировки
     * @return array
     */
    static public function getCsvCharsets()
    {
        return [
            self::CSV_CHARSET_UTF8              => self::CSV_CHARSET_UTF8,
            self::CSV_CHARSET_WINDOWS1251       => self::CSV_CHARSET_WINDOWS1251,
        ];
    }


    public function init()
    {
        $this->name = \Yii::t('skeeks/exportCsvContent', '[CSV] Export content items');

        if (!$this->file_path)
        {
            $rand = \Yii::$app->formatter->asDate(time(), "Y-M-d") . "-" . \Yii::$app->security->generateRandomString(5);
            $this->file_path = "/export/content/content-{$rand}.csv";
        }

        parent::init();
    }

    public function getAvailableFields()
    {
        $element = new CmsContentElement([
            'content_id' => $this->cmsContent->id
        ]);

        $fields = [];

        foreach ($element->attributeLabels() as $key => $name)
        {
            $fields['element.' . $key] = $name;
        }

        foreach ($element->relatedPropertiesModel->attributeLabels() as $key => $name)
        {
            $fields['property.' . $key] = $name . " [свойство]";
        }

        return array_merge(['' => ' - '], $fields);
    }

    /**
     * @return null|CmsContent
     */
    public function getCmsContent()
    {
        if (!$this->content_id)
        {
            return null;
        }

        return CmsContent::findOne($this->content_id);
    }



    /**
     * Соответствие полей
     * @var array
     */
    public $matching = [];

    public function rules()
    {
        return ArrayHelper::merge(parent::rules(), [

            ['content_id' , 'required'],
            ['content_id' , 'integer'],

            ['charset' , 'string'],

            [['matching'], 'safe'],
            [['matching'], function($attribute) {
                if (!in_array('element.name', $this->$attribute))
                {
                    $this->addError($attribute, "Укажите соответствие названия");
                }
            }]
        ]);
    }

    public function attributeLabels()
    {
        return ArrayHelper::merge(parent::attributeLabels(), [
            'content_id'        => \Yii::t('skeeks/importCsvContent', 'Контент'),
            'matching'          => \Yii::t('skeeks/importCsvContent', 'Preview content and configuration compliance'),
            'charset'          => \Yii::t('skeeks/importCsvContent', 'Кодировка'),
        ]);
    }

    /**
     * @param ActiveForm $form
     */
    public function renderConfigForm(ActiveForm $form)
    {
        parent::renderConfigForm($form);

        echo $form->field($this, 'charset')->listBox(
            $this->getCsvCharsets(), [
                'size' => 1,
                'data-form-reload' => 'true'
            ]);

        echo $form->field($this, 'content_id')->listBox(
            array_merge(['' => ' - '], CmsContent::getDataForSelect()), [
            'size' => 1,
            'data-form-reload' => 'true'
        ]);
    }



    public function export()
    {
        ini_set("memory_limit","8192M");
        set_time_limit(0);

        //Создание дирректории
        if ($dirName = dirname($this->rootFilePath))
        {
            $this->result->stdout("Создание дирректории\n");

            if (!is_dir($dirName) && !FileHelper::createDirectory($dirName))
            {
                throw new Exception("Не удалось создать директорию для файла");
            }
        }



        $query = CmsContentElement::find()->where([
            'content_id' => $this->content_id
        ]);

        $countTotal = $query->count();

        $this->result->stdout("\tЭлементов найдено: {$countTotal}\n");


        /**
         * Формируем шапку
         */
        $firstElemForHeader = $query->one();

        $head = [];
        foreach ($firstElemForHeader->toArray() as $code => $value)
        {
            $head[] = "element." . $code;
            unset($value);
        }
        /**
         * @var $element CmsContentElement
         */
        foreach ($firstElemForHeader->relatedPropertiesModel->toArray() as $code => $value)
        {
            $head[] = 'property.' . $code;
            unset($value);
        }


        $head[] = "v3code;url;image";

        /**
         * откроем файл и запишем туда шапку
         */
        $fp = fopen($this->rootFilePath, 'w');
        fputcsv($fp, $head, ";");
        fclose($fp);
        unset($fp, $head, $firstElemForHeader, $v3property, $shopCmsContentElement);

        /**
         * Чтобы не загромождать память, разбиваем результаты на страницы
         */

        $i = 0;
        $pages = new Pagination([
            'totalCount'        => $countTotal,
            'defaultPageSize'   => $this->max_urlsets,
            'pageSizeLimit'   => [1, $this->max_urlsets],
        ]);

        for ($i >= 0; $i < $pages->pageCount; $i ++) {
            $pages->setPage($i);

            $this->result->stdout("\t\t\t\t Page = {$i}\n");
            $this->result->stdout("\t\t\t\t Offset = {$pages->offset}\n");
            $this->result->stdout("\t\t\t\t limit = {$pages->limit}\n");



            foreach ($elements = $query->offset($pages->offset)->limit($pages->limit)->each(20) as $element)
            {
                $this->result->stdout("\tТовар: ". $element->name . "\n");

                $fp = fopen($this->rootFilePath, 'a');

                $propertiesRow = [];

                foreach ($element->relatedPropertiesModel->toArray() as $code => $value)
                {
                    $value = $element->relatedPropertiesModel->getSmartAttribute($code);
                    $intValue = (int) $value;
                    $propertyValue = '';

                    if ($intValue>0)
                    {
                        $_property = Tree::findOne(['id'  =>  (int) $value]);
                        if ($_property)
                        {
                            $propertyValue = $_property->name;
                            $propertiesRow[$code] = $propertyValue;
                            unset($_property, $value);
                            continue;
                        }
                        $_property = CmsContentElement::findOne(['id'  =>  (int) $value]);
                        if ($_property)
                        {
                            $propertyValue = $_property->name;
                            $propertiesRow[$code] = $propertyValue;
                            unset($_property, $value);
                            continue;
                        }

                    }
                    if (is_array($value))
                    {
                        /**
                         * @var $_property CmsContentElement
                         */

                        foreach ($value as $key => $val_id)
                        {
                            $_property = CmsContentElement::findOne(['id'  => (int) $val_id]);

                            $propertyValue .= $_property->name;
                            if ($key<count($value))
                            {
                                $propertyValue .=  ', ';
                            }
                        }
                    }
                    else
                    {
                       $propertyValue = $value;
                    }

                    $propertiesRow[$code] = $propertyValue;
                    unset($value, $_property);
                }
                unset($_property, $intValue);

                $shopCmsContentElement = new \v3toys\skeeks\models\V3toysProductContentElement($element->toArray());

                $row = array_merge($element->toArray(), $propertiesRow, [$shopCmsContentElement->v3toysProductProperty->v3toys_id, $element->url, $element->image->src]);
                unset($element, $shopCmsContentElement);
                if (\Yii::$app->charset != $this->charset)
                {
                    foreach ($row as $key => $value)
                    {
                        if (is_string($value))
                        {
                            $row[$key] = iconv(\Yii::$app->charset, $this->charset, $value);
                        }
                    }
                }

                fputcsv($fp, $row, ";");

                fclose($fp);
                unset($row );
            }
            unset($elements);

        }

        $this->result->stdout("\tФайл сформирован по указанному пути \n". $this->rootFilePath ."\n");
        die();
    }
}