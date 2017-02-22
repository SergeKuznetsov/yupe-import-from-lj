<?php

// Размещаем этот файл в папку commands. И называем по имени класса LjimportCommand.php
// Вызываем из папки protected как php yiic.php ljimport import

// Подключаем библиотеку для работы с XML-RPC
require('../vendor/IXR_Library/IXR_Library.inc.php');

// Настройка подключения к серверу
define('LJ_HOST',   'www.livejournal.com');
define('LJ_PATH',   '/interface/xmlrpc');
define('LJ_LOGIN',  '');
define('LJ_PASSWD', '');

// С какой даты будем получать посты, в формате, перебирая интервалы
define('IMPORT_FROMDATE', '2006-01-01 00:00:00');

// Период запроса, т.к. за раз сервер возвращает только 100 записей,
// этот период должен быть таким, что бы за это время не было превышено 100 записей, 
// например 2 месяца будет как 'P2M', один год 'P1Y', см. класс DateInterval
define('IMPORT_INTERVALDATE', 'P6M');

// Настройка добавления записей в БД.
define('IMPORT_BLOG_ID', 1);		// ID блога в который будем все импортировать
define('IMPORT_USER_ID', 2);		// ID пользователя от имени которого будет добавлена запись
define('IMPORT_POST_STATUS', 1);	// 0-черновик, 1-опубликовано, 2-задание, 3-модерация, 4-удален.


set_time_limit(0);

// Переопределим класс. Нужно 'перебить' метод beforeSave.
class PostFromLJ extends Post
{
    public function beforeSave()
    {

        $this->setTags($this->tags);
		
		$m = (new ReflectionClass($this))->getParentClass()->getParentClass();

		return $m->getMethod('beforeSave');

    }	
}

// Основной класс для импорта
class LjimportCommand extends CConsoleCommand
{
	// Массив импортируемых постов
	private  $posts = array(); 

	
    public function actionImport() {

        echo 'Import...' . PHP_EOL;
		$this->get_data();
		$this->put_data();
		
    }
	
	// Получаем данные с сервера.
    private function get_data() {

		$current = new DateTime();								// Текущее время
		$date = new DateTime(IMPORT_FROMDATE);					// Начальная дата
		$interval = new DateInterval(IMPORT_INTERVALDATE);		// Интервал, см.коммент к константе
	
		// Создаем xml-rpc клиента
		$ljClient = new IXR_Client(LJ_HOST, LJ_PATH);

		// Заполняем поля XML-запроса
		$ljMethod = 'LJ.XMLRPC.getevents';
		
		$ljArgs = array();
		
		if(empty(LJ_LOGIN)) {
			echo 'Livejornal login: ';
			$ljArgs['username'] = trim(fgets(STDIN));
		}
		else $ljArgs['username'] = LJ_LOGIN;
		
		if(empty(LJ_PASSWD)) { 
			echo 'Livejornal password: ';
			$ljArgs['password'] = trim(fgets(STDIN));
		}
		else $ljArgs['password'] = LJ_PASSWD;
		
		$ljArgs['auth_method'] = 'clear';
		$ljArgs['ver']  = "1";			
		$ljArgs['selecttype'] = 'syncitems';
		
		// Получаем нужные данные, с начала периода, прибавляя интервал.
		do {

			echo 'Запрос с ' . $date->format('Y-m-d H:i:s') . PHP_EOL;
			
			// Определим начало получения данных
			$ljArgs['lastsync'] = $date->format('Y-m-d H:i:s');		

			// Посылаем запрос
			if (!$ljClient->query($ljMethod, $ljArgs)) {
				echo 'Ошибка [' . $ljClient->getErrorCode() . '] ' . $ljClient->getErrorMessage() . PHP_EOL;
			}
			else {
				
				// Получаем ответ
				$ljResponse = $ljClient->getResponse();
				
				foreach ($ljResponse['events'] as $event) {
					
					// Обрабатываем только публичные записи
					if(!isset($event['security'])) {
						
						// Если еще не обработали
						if(!isset($posts[$event['itemid']])) {
							$this->posts[$event['itemid']] = array(
							
								'slug' => 'lj' . $event['itemid'],	// Формируем url, subject задана не всегда
								
								'itemid' => $event['itemid'], 
								'blog_id' => IMPORT_BLOG_ID,
								'create_user_id' => IMPORT_USER_ID,
								'update_user_id' => IMPORT_USER_ID,
								'status' => IMPORT_POST_STATUS, 
								
								'title' => isset($event['subject']) ? $event['subject'] : substr(strip_tags($event['event']), 0, 100) . '.', // Если не задана тема -- возникает ошибка.
								'content' => $event['event'],
								'create_time' => $event['event_timestamp'],
								'publish_time' => $event['event_timestamp'],
								
								'comment_status' => $event['can_comment'],
								'tags' => isset($event['props']['taglist']) ? $event['props']['taglist'] : '', 
								'link' => $event['url'],								
							);
						}
					}
				}
				
				echo "Всего получено:" . count($ljResponse['events']) .' обработано:' . count($this->posts) . PHP_EOL;
			}
		} while($date->add($interval) < $current);
        
    }	
	
	// Вставляем данные в блог
    private function put_data() {
		
		// Вставим полученные данные в БД
		foreach ($this->posts as $post) {
			
			$model = new PostFromLJ();
		
			$model->setAttributes($post);
			
            if ($model->save())
				echo 'Добавлена запись с itemid: ' . $post['itemid'] . ' url: ' . $post['link'] . PHP_EOL;
			else
				echo 'Произошла ошибка при добавлении записи с itemid:' . $post['itemid'] . ' ' . print_r($model->errors). PHP_EOL;

			unset($foo);
		}
				
	}	
}