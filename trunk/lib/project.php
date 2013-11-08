<?php
/*************************************
 *  Класс отображения заведения
 ************************************/
class point extends item_viewer{
	function get_data(){
	  if(!empty($_SESSION['Jlib_auth']['u_id']))
	  	$you_sql_add=" ,(SELECT l2.l_weight FROM likes l2 WHERE l2.l_key_obj=p_id AND l2.l_type='pnt' AND l_key_u=".$_SESSION['Jlib_auth']['u_id'].") p_you ";
	  else
	    $you_sql_add=" ,'' p_you ";
	
	  $this->data=$GLOBALS[CM]->run("sql:point LEFT JOIN likes ON(l_key_obj=p_id AND l_type='pnt')
	                                #*,
																		SUM(l_weight) p_weight,
																		COUNT(l_weight) p_votes,
																	 (SELECT COUNT(l1.l_key_obj) FROM likes l1 WHERE l1.l_key_obj=p_id AND l1.l_type='pnt' AND l_weight>0) p_plus_cnt
																	 ".$you_sql_add."
																	?p_url='".$this->ctrl['p_url']."'\$auto_query=no group=p_id shrink=yes limit=0,1");
		//Если нет foursquare идентификатора - ничего не выйдет
	  if(empty($this->data['p_fsid'])){
	    $this->data=array();
	    return false;
		}
		
		//Обращение к foursquare (там есть кэш)
		$GLOBALS['FS']=init_fs();
		$dt=$GLOBALS['FS']->venue( $this->data['p_fsid'] );
		//echo '<pre class="debug">DT:'.print_r ( $dt ,true).'</pre>';
		if(!$dt){
		  echo '<pre class="debug">'.$GLOBALS['FS']->cur_json.'</pre>';
		  $this->data=array(); return true;
		}
		
		//Запихиваю данные из fourscquare в $this->data
		$this->data=array_merge($this->data, parse_venue_item($dt));

		//Выбрать каменты из БД
		$t=$GLOBALS[CM]->run('sql:comment,user
													#com_id,com_type,com_key_obj,com_weight,com_key_u,com_text,com_short,com_cachelikes,com_cahecomms,
                           u_id,u_grp,u_url,u_email,u_name,u_img,u_gender,u_createdate,u_lastlogin,u_lock,
													DATE_FORMAT( com_date ,\'%Y%m%d%H%i%s\') did,
													DATE_FORMAT( com_date ,\'%d.%m.%Y %H:%i\') com_date
													?com_type=\'pnt\' AND com_key_obj='.$this->data['p_id'].' AND com_key_u=u_id$id=did ');

		$this->data['tips']=array_merge($this->data['tips'], $t);
		krsort($this->data['tips']);
		unset($t);
	}
	
	function before_parse(){
	  parent::before_parse();
	  $repl=array(
	    'gallery'=>'',
	    'auth'=>'',
	    'comms'=>'',
		);
		/*****************
		 *  Галерея
		 *****************/
	  $p=array();
	  if(!empty($this->data['photos'])){
			foreach( $this->data['photos'] as $v ){
		    $p[]=strjtr( $this->tpl['gallery_item'],$v );
			}
			$repl['gallery']=implode('', $p);
		}else
		  $repl['gallery']='';
		
		/*****************
		 *  auth
		 *****************/
		 $repl['auth']=(!empty($_SESSION['Jlib_auth']))?$this->tpl['auth']:$this->tpl['not_auth'];
		/*****************
		 *  comments
		 *****************/
			$vals=array();
			if(!empty($this->data['tips'])){
				foreach($this->data['tips'] as $k=>$v){
					if(!empty($v['com_id']))$tpl=$this->tpl['comm_line'];
					else  $tpl=$this->tpl['comm_line_fs'];
					if($v['com_weight']>0)$v['com_weight']=$this->tpl['comm_weight_plus'];
					elseif($v['com_weight']<0)$v['com_weight']=$this->tpl['comm_weight_minus'];
					else $v['com_weight']='';
					
					$vals[]=strjtr($tpl,$v);
				}
				$repl['comms']=str_replace('{body}',implode('',$vals),$this->tpl['comm_body']);
			}else
			  $repl['comms']='';
			unset($vals);
			$repl['comm_p_fscat_prim']=$this->data['p_fscat_prim'];
			$repl['comm_p_name']=$this->data['p_name'];
			
			//.... подстановка
			$this->pg=strjtr($this->pg,$repl);
		/*****************
		 *  Голосовалка / рекоммендовалка
		 *****************/
		 
	}
}
/*************************************
 *  Класс добавления заведения в базу
 ************************************/
class point_add extends icontrol{
	function make(){
	  //ТОчно такой точки нет?
	  $tst=$GLOBALS[CM]->run('sql:point?p_fsid=\''. mysql_real_escape_string($GLOBALS['Jlib_page_extra'][0]) .'\'$limit=0,1 shrink=yes ');
	  if(!empty($tst)){
	    redirect('/point/'.$tst['p_url']);
	    exit();
		}

	  $GLOBALS['FS']=init_fs();
		if(!$dt=$GLOBALS['FS']->venue( $GLOBALS['Jlib_page_extra'][0] )){
		  echo "Ошибка:( <script>//history.back();</script>";
	    exit();
		}
		$item=parse_venue_item($dt);
//echo '<pre class="debug">'.print_r ( $item ,true).'</pre>'; exit();
		//УРЛ
		$item['p_url']=strtolower(translit( $item['p_name'] ));
		$t=$GLOBALS[CM]->run('sql:point?p_url=\''.$item['p_url'].'\'');
		if(!empty($t)) $item['p_url'].=uniqid('-');
		//Картинка
		if(!empty($item['photos']))
			$item['p_img']=str_replace('/150x150/','/200x150/',$item['photos'][0]['photo']);
		//Описание
		$item['p_dscr']= $item['p_fs_reasons'].' '.$item['p_fs_atts'];
		
		//Регион
		//Спрашиваю гугль по русски, БЛИН!
		$coord=json_decode(file_get_contents('http://maps.googleapis.com/maps/api/geocode/json?language=ru&address='.$item['p_lat'].','.$item['p_lng'].'&sensor=false'),1);
		if(!empty($coord['results'])){
		  for($i=0; $i<count($coord['results']); $i++){
					if( $coord['results'][$i]['types'][0]=='locality' ){
					  $item['p_reg_name']=$coord['results'][$i]['address_components'][0]['short_name'];
						$item['p_reg_lat']=$coord['results'][$i]['geometry']['location']['lat'];
						$item['p_reg_lng']=$coord['results'][$i]['geometry']['location']['lng'];
					}
			}
		}
		
		//Смотрю, а есть ли такой регион в БД
		$r_url=translit($item['p_reg_name']);
		$r_url=strtr($r_url, array('%'=>'','"'=>'',"'"=>'','+'=>'','/'=>'','\\'=>''));
		
		if(!$reg_id=$GLOBALS[CM]->run("sql:region#r_id?r_url='".$r_url."'\$limit=0,1 shrink=yes")){
		  $reg_id=$GLOBALS[CM]->run("sql:region",'insert',array(
		    'r_url'=>$r_url,
		    'r_name'=>$item['p_reg_name'],
		    'r_lat'=>$item['p_reg_lat'],
				'r_lng'=>$item['p_reg_lng'],
			));
		}
		$item['p_key_reg']=$reg_id;

		$item['p_createdate']=date('Y-m-d H:i:s');

		//Добавляю
		$p_id=$GLOBALS[CM]->run('sql:point#p_id,p_url,p_fsid,p_name,p_img,p_dscr,p_key_reg,p_addr,p_lat,p_lng,p_createdate','insert',$item);
		
		//Добаить индексы по имени
		$item['p_name']=strtr(
			$item['p_name'] ,
			array( '"'=>' ', "'"=>' ', '`'=>'', '/'=>'', '_'=>' ', '-'=>' ', '  '=>'')
		);
		$m=explode(' ', $item['p_name']);
    $sql="REPLACE INTO search_words_index VALUES ";
    $vals=array();
    foreach( $m as $v ){
      if(empty($v)) continue;
      if( strlen($v)>1 )
        $vals[]=" ('$v', $p_id, 'pnt') ";
		}
		$tms=explode(' ',implode(' ', explode(',',$item['p_themes'])));
		foreach($tms as $v){
		  $vals[]=" ('".trim($v)."', $p_id, 'pnt') ";
		}
		$sql.=implode(',',$vals);
		$db=init_db();
		$db->query($sql);
		
		//Добавить привязку к категориям
		$sql="REPLACE INTO point2theme (SELECT $p_id , t2c_key_t FROM theme2fs_cat WHERE  t2c_key_c IN( '". implode("','", array_keys( $item['p_fs_cats'] ) ) ."' ) )";
		$db->query($sql);
		
		redirect('/point/'.$item['p_url']);
	  exit();
	}
}

/*************************************
 *  Класс расширяющйи list viewer
 ************************************/
class proj_list_viewer extends list_viewer{
	function get_data(){
	  if( !empty($this->params['get_data']) && function_exists($this->params['get_data']) ){
	    $this->data=$this->params['get_data']($this);
		}
	}
}

/*************************************
 *  SEARCH Поиск объектов в FS и в локальной базе, соответствующих запросу пользователя.
 ************************************/
function project_search_points(&$obj){
	if(empty($obj->ctrl['query']) || (empty($obj->ctrl['region']) && empty($obj->ctrl['region_other']) )){
	  return false;
	}
	
	$data=array();
	
	//Данные из  foursquare
	$data_fs=array();
	$request=array(
	  'query'=> ( mysql_real_escape_string($obj->ctrl['query'] ) ) ,
	  'location'=> (!empty($obj->ctrl['region_other']))? ( urlencode( mysql_real_escape_string($obj->ctrl['region_other'] ) ) ) :( mysql_real_escape_string($obj->ctrl['region'] ) ) ,
	);
	//echo '<pre class="debug">'.print_r ( $request ,true).'</pre>';
	$GLOBALS['FS']=init_fs();
	if(!$dt=$GLOBALS['FS']->search( $request )){
	  //echo '<pre class="debug">'.print_r ( $GLOBALS['FS']->errors ,true).'</pre>';
		//return false;
	}else{
		//Запихиваю данные из fourscquare в $this->data
	  $d=&$dt->response->venues;

	  for($i=0,$cnt=count($d);$i<$cnt;$i++){
	    $data_fs[ $d[$i]->id ]=array(
        'id'=>$d[$i]->id,
	      'name'=>$d[$i]->name,
	      'address'=>(!empty($d[$i]->location->address))?$d[$i]->location->address:'',
	      'img'=>(!empty($d[$i]->categories[0]))?($d[$i]->categories[0]->icon->prefix.'88'.$d[$i]->categories[0]->icon->suffix):'',
	      'spec'=>(!empty($d[$i]->specials->count))?($d[$i]->specials->items[0]->message):'',
			);
			for($j=0,$cats=array(),$jcnt=count($d[$i]->categories); $j<$jcnt ;$j++){
			  $cats[]=$d[$i]->categories[$j]->name;
			}
			$data_fs[ $d[$i]->id ]['categories']=implode(', ',$cats); unset($cats);
		}
	}

	//Данные из  бызы
	if( empty($obj->ctrl['region_other']) ){
	  $obj->ctrl['region']=mysql_real_escape_string($obj->ctrl['region']);
	  $t=explode(' ', trim(strtr(urldecode($obj->ctrl['query']), array('  '=>' '))) );
	  $words=array();
	  foreach($t as $w){
	    $w= trim($w);
			if( strlen( $w )>1 ) $words[]=$w;
		}
		if(!empty($words)){
		  $data_db=$GLOBALS[CM]->run("sql:region,point LEFT JOIN
																			comment ON(com_type='pnt' AND	com_key_obj=p_id) LEFT JOIN
																			user ON(com_key_u=u_id),
																			search_words_index
																	#p_id,p_url,p_fsid,p_name,p_img,p_dscr,p_key_reg,p_addr,p_lat,p_lng,p_createdate,
                                   r_name,r_url,r_lat,r_lng,
                                   com_id,com_date, com_short,com_cachelikes,com_cahecomms,
                                   u_id,u_url,u_email,u_name,u_img,u_gender,u_createdate,u_lastlogin,
                                   count(sw_key_obj) p_relevant,
                                   count( com_id ) p_comms
																	?r_url='". $obj->ctrl['region'] ."' AND
																	 p_key_reg=r_id AND
																	 sw_key_obj=p_id AND	sw_obj_type='pnt' AND (
																	 	sw_word='". implode("' OR sw_word='", $words) ."')
																	\$group=p_id id=p_fsid order=p_relevant,p_name direction=desc,asc");
			
		}
	}
	
	
	if(empty($data_fs)) $data_fs=array();
	if(empty($data_db)) $data_db=array();
	$data=array_merge($data_fs, $data_db);
	
	return $data;
}


function parse_venue_item($dt){
    $d=&$dt->response->venue;
  	//echo '<pre class="debug">'.print_r ( $dt ,true).'</pre>';
  	$res_data=array();
  	$res_data['p_fsid']=$d->id;
		$res_data['p_name']=$d->name;
		if(!empty($d->contact->formattedPhone))
			$res_data['p_phone']=$d->contact->formattedPhone;
		$res_data['p_addr']=$d->location->city.', '.$d->location->address;

		//РЕГИОН
		$res_data['p_reg_name']=$d->location->city;
		$res_data['p_lat']=$d->location->lat;
		$res_data['p_lng']=$d->location->lng;

		$tags=array();
		for($i=0; $i<count($d->categories); $i++){
		  if( $d->categories[$i]->primary ) $res_data['p_fscat_prim']= $d->categories[$i]->name;
		  $tags[ $d->categories[$i]->id ] = $d->categories[$i]->name;
		}
		$res_data['p_themes']= implode(',', $tags);
		$res_data['p_fs_cats']= $tags;

		if(!empty($d->url)) $res_data['p_site']= $d->url;
		else $res_data['p_site']='';
		
		$res_data['p_fs_likes']= $d->likes->count;

		$res_data['photos']=array();
		if(!empty($d->photos)){
			for($i=0; $i<count($d->photos->groups); $i++){
			  for($j=0; $j<count($d->photos->groups[$i]->items); $j++){
			    $res_data['photos'][]=array(
			      'photo'=>$d->photos->groups[$i]->items[$j]->prefix.'150x150'.$d->photos->groups[$i]->items[$j]->suffix,
			      'photo_big'=>$d->photos->groups[$i]->items[$j]->prefix.'original'.$d->photos->groups[$i]->items[$j]->suffix,
			      'title'=>$d->photos->groups[$i]->name.' '.$d->name
					);
			  }
			}
		}

		if(!empty($d->reasons)){
			$res_data['reasons']=array();
			for($i=0; $i<count($d->reasons->items); $i++){
			  $res_data['reasons'][]=$d->reasons->items[$i]->summary;
			}
			$res_data['p_fs_reasons']= implode(', ', $res_data['reasons']);
		}else $res_data['p_fs_reasons']='';

    $res_data['tips']=array();
		if(!empty($d->tips)){
			for($i=0; $i<count($d->tips->groups); $i++){
			  for($j=0; $j<count($d->tips->groups[$i]->items); $j++){
			    $res_data['tips'][ date('YmdHis', $d->tips->groups[$i]->items[$j]->createdAt ) ]=array(
			      'text'=>$d->tips->groups[$i]->items[$j]->text,
			      'create'=>date('d.m.Y', $d->tips->groups[$i]->items[$j]->createdAt ),
			      'user_id'=>$d->tips->groups[$i]->items[$j]->user->id,
			      'user_name'=>$d->tips->groups[$i]->items[$j]->user->firstName.' '.$d->tips->groups[$i]->items[$j]->user->lastName,
			      'user_photo'=>$d->tips->groups[$i]->items[$j]->user->photo->prefix.'/50x50/'.$d->tips->groups[$i]->items[$j]->user->photo->suffix,
					);
			  }
			}
		}

    if(!empty($d->attributes)){
			$atts=array();
			for($i=0; $i<count($d->attributes->groups); $i++){
			  for($j=0; $j<count($d->attributes->groups[$i]->items); $j++){
			    $atts[]=$d->attributes->groups[$i]->items[$j]->displayName;
			  }
			}
			$res_data['p_fs_atts']= implode(', ', $atts);
		}else $res_data['p_fs_atts']='';

		if(!empty($d->hours)){
			$hours=array();
			for($i=0; $i<count($d->hours->timeframes); $i++){
			  $hours[]=$d->hours->timeframes[$i]->days.', '.$d->hours->timeframes[$i]->open[0]->renderedTime;
			}
			$res_data['p_timeframes']= implode(', ', $hours);
		}$res_data['p_timeframes']='';
	return $res_data;
}

class proj_get_meta extends get_meta{
	function init(){
	  parent::init();
	  if(empty($_SESSION['Jlib_auth'])){
	    //$GLOBALS['Jlib_meta']
	    $t=$GLOBALS[CM]->run('sql:sys_meta?mt_pg=\'not_auth\'$order=mt_order ');
	    //echo '<pre class="debug">'.print_r ( $GLOBALS['Jlib_meta'] ,true).'</pre>';
	    foreach($t as $v){
	      if(!isset($GLOBALS['Jlib_meta'][ $v['mt_typ'] ]))
	        $GLOBALS['Jlib_meta'][ $v['mt_typ'] ]=array();
	      $GLOBALS['Jlib_meta'][ $v['mt_typ'] ][]=$v['mt_cnt'];
			}
		}
	}
}

class nav_cats extends list_viewer{
	function init(){
	  if(empty($_SESSION['Jlib_auth']))
			$this->params['ucl']='sql:theme';
		else
		  $this->params['ucl']='sql:theme,user2theme?u2t_key_t=t_id AND u2t_key_u='.$_SESSION['Jlib_auth']['u_id'];

		if(!empty($this->ctrl['theme'])){
		  $this->pg=str_replace('{body}', $this->tpl['link_all'].'{body}', $this->pg);
		}
		parent::init();
	}
}

function point_stat($prm){
	
	$prm['parent']->pg='';
	
	//echo '<pre class="debug">'.print_r ( $GLOBALS['Jlib_frame']->obj['block1']->data ,true).'</pre>';
	/*
	//[p_weight] => -1
  //[p_votes] => 1
  //[p_plus_cnt] => 0
		  p_votes 		- 100%
		  p_plus_cnt	- ?%
  */

  $repl=$GLOBALS['Jlib_frame']->obj['block1']->data;
  //weight 1,0,-1
		if( !empty($repl['p_weight']) ) $repl['p_weight']=($repl['p_weight']>1)?1:-1;

	return strjtr($prm['parent']->tpl['statistic'], $repl );

  $pdt=&$GLOBALS['Jlib_frame']->obj['block1']->data;

	if(!empty($pdt['p_votes']) && empty($pdt['p_weight'])) $pdt['p_weight']=1;

	if(!empty($_SESSION['Jlib_auth']['u_id'])){
	  if(
			!empty($pdt['p_you']) &&
			($pdt['p_you']/abs($pdt['p_you'])) == ($pdt['p_weight']/abs($pdt['p_weight']))
		) $repl['youtoo']=$prm['parent']->tpl['stat_youtoo'];
		$repl['stat_act']=$prm['parent']->tpl['stat_act'];
	}else{
	  $repl['stat_act']=$prm['parent']->tpl['stat_act_auth'];
	  $repl['loginza_token']=loginza_token_url();
	}

  //statistic_empty
  if(empty($pdt['p_votes'])){
    return strjtr($prm['parent']->tpl['statistic_empty'], $repl );
	}

  //Плюсовой процент
  $repl['pct']=$pdt['p_plus_cnt']*100/ $pdt['p_votes'];
  
  if( $pdt['p_weight']<0 ){//Минусовой процент
		$repl['pct']=100-$repl['pct'];
		$repl['recommend']=$prm['parent']->tpl['stat_not_recommend'];
	}elseif($pdt['p_weight']==0){  //Поровну
		//Чтобы нуля не было.
		$repl['p_weight']=$pdt['p_weight']=1;
		$repl['recommend']=$prm['parent']->tpl['stat_recommend'];
	}else{  //Большинство ЗА!
	  $repl['p_weight']=$pdt['p_weight']=1;
	  $repl['recommend']=$prm['parent']->tpl['stat_recommend'];
	}
	//Большинство или все
	if( $repl['pct']<100 ) $repl['many']=$prm['parent']->tpl['many'];
	else  $repl['many']=$prm['parent']->tpl['many_all'];

  $repl['p_weight']=( $repl['p_weight']>0 )?1:-1;
  //Мнение пользователя
  //$repl['youtoo']='';


	return strjtr($prm['parent']->tpl['statistic'], $repl );
}

function display_search_query($prm){
	return mysql_real_escape_string($_GET['query']);
}
?>