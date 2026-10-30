$(function(){
	const ap = new APlayer({
		container: document.getElementById('poi-play-bar'),
		fixed: true,
		order: 'random',
		lrcType: 3,
        // audio: [{
        //     name: '渡月橋 ～君 想ふ～',
        //     artist: '倉木麻衣',
        //     url: 'https://i9.lemonpig.cn/song/01/web.mp3',
        //     cover: 'https://i9.lemonpig.cn/song/01/pic.jpg',
        //     lrc: 'https://i9.lemonpig.cn/song/01/lyric.txt'
        // }]
	});
    function readlist(server, id) {
        jQuery.ajax({
            type: 'GET',
            url: "https://www.lemonpig.cn/wp-content/themes/H-Siren/meting/api.php?server="+server+"&type=playlist&id="+id,
            dataType: 'json',
            timeout: 30000,
            success: function(json) {
                if (!json.length) {
                    ap.notice('歌单为空，或暂时无法获取', 5000);
                    return;
                }
                ap.list.add(json.map(item => ({
                    name: item.title,
                    artist: item.author,
                    url: item.url,
                    cover: item.pic,
                    lrc: item.lrc
                })));
            },
            error: function() {
                ap.notice('歌单加载失败，请稍后刷新重试', 5000);
            }
        });
    }
	//readlist("xiami",1296321389);
	readlist("netease",5368017031);
})
/*
 * API List
https://api.i-meto.com/meting/api?server="+server+"&type=playlist&id="+id,
https://www.lemonpig.cn/wp-content/themes/H-Siren/meting/api.php?server=:server&id=:id&type=playlist
https://www.lemonpig.cn/api/meting/api.php?server=:server&id=:id&type=playlist
https://www.lemonpig.cn/api/meting/api.php?server="+server+"&type=playlist&id="+id,
https://www.lemonpig.cn/wp-content/themes/H-Siren/meting/api.php?server="+server+"&type=playlist&id="+id,
*/
