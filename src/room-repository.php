<?php

declare(strict_types=1);

function room_deletion_allowed(int $bookingCount): bool { return $bookingCount === 0; }
function room_is_public(string $status): bool { return in_array($status, ['ACTIVE','COMING_SOON'], true); }
function select_package_id(string $requested, array $ownedIds): string { return $requested !== '' && in_array($requested,$ownedIds,true) ? $requested : uuid(); }

function hydrate_room_rows(array $rooms, array $images, array $tags, array $packages): array
{
    $result = [];
    foreach ($rooms as $row) {
        $id = (string)$row['id'];
        $roomImages = array_values(array_filter($images, fn($item) => $item['room_id'] === $id));
        usort($roomImages, fn($a,$b) => ((int)$b['is_cover'] <=> (int)$a['is_cover']) ?: ((int)$a['sort_order'] <=> (int)$b['sort_order']));
        $roomTags = array_values(array_filter($tags, fn($item) => $item['room_id'] === $id));
        usort($roomTags, fn($a,$b) => (int)$a['sort_order'] <=> (int)$b['sort_order']);
        $roomPackages = array_values(array_filter($packages, fn($item) => $item['room_id'] === $id));
        usort($roomPackages, fn($a,$b) => (int)$a['sort_order'] <=> (int)$b['sort_order']);
        $mappedImages = array_map(fn($item) => [
            'id'=>$item['id'],'path'=>$item['path'],'caption'=>$item['caption'] ?? '',
            'isCover'=>(bool)$item['is_cover'],'sortOrder'=>(int)$item['sort_order'],
        ], $roomImages);
        $mappedPackages = array_map(fn($item) => [
            'id'=>$item['id'],'name'=>$item['name'],'mode'=>$item['timing_mode'],
            'durationMinutes'=>$item['duration_minutes'] === null ? null : (int)$item['duration_minutes'],
            'checkInTime'=>$item['check_in_time'] === null ? null : substr((string)$item['check_in_time'],0,5),
            'checkOutTime'=>$item['check_out_time'] === null ? null : substr((string)$item['check_out_time'],0,5),
            'price'=>(int)$item['price'],'enabled'=>(bool)$item['is_enabled'],'sortOrder'=>(int)$item['sort_order'],
        ], $roomPackages);
        $result[$id] = [
            'id'=>$id,'name'=>$row['name'],'slug'=>$row['slug'],'subtitle'=>$row['subtitle'] ?? '',
            'description'=>$row['description'] ?? '','tag'=>$row['tag'] ?? '','status'=>$row['status'] ?? 'ACTIVE',
            'maxGuests'=>(int)$row['max_guests'],'bedrooms'=>(int)($row['bedrooms'] ?? 1),'bathrooms'=>(int)($row['bathrooms'] ?? 1),
            'sortOrder'=>(int)($row['sort_order'] ?? 0),'isActive'=>($row['status'] ?? 'ACTIVE') === 'ACTIVE',
            'image'=>$mappedImages[0]['path'] ?? '/images/white-illustration.jpg','images'=>$mappedImages,
            'tags'=>array_map(fn($item)=>(string)$item['label'],$roomTags),'packages'=>$mappedPackages,
            'createdAt'=>$row['created_at'] ?? null,'updatedAt'=>$row['updated_at'] ?? null,
        ];
    }
    return $result;
}

final class RoomRepository
{
    public function __construct(private PDO $pdo) {}

    public function publicRooms(): array
    {
        $rooms = $this->load("status IN ('ACTIVE','COMING_SOON')");
        foreach ($rooms as &$room) $room['packages'] = array_values(array_filter($room['packages'], fn($package) => $package['enabled']));
        return $rooms;
    }

    public function allRooms(): array { return $this->load('1=1'); }

    public function findById(string $id): ?array { return $this->load('id=?', [$id])[$id] ?? null; }

    public function findPublicBySlug(string $slug): ?array
    {
        foreach ($this->publicRooms() as $room) if ($room['slug'] === $slug) return $room;
        return null;
    }

    public function create(array $input): array
    {
        $data = validate_room_input($input); $id = uuid();
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("INSERT INTO rooms(id,name,slug,price,max_guests,is_active,subtitle,description,tag,status,bedrooms,bathrooms,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $minimumPrice = $this->minimumPrice($data['packages']);
            $stmt->execute([$id,$data['name'],$data['slug'],$minimumPrice,$data['maxGuests'],$data['status']==='ACTIVE'?1:0,$data['subtitle'],$data['description'],'',$data['status'],$data['bedrooms'],$data['bathrooms'],$data['sortOrder']]);
            $this->replaceTags($id,$data['tags']); $this->replacePackages($id,$data['packages']);
            $this->pdo->commit();
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ((string)$e->getCode()==='23000') throw new BookingException('Slug phòng đã tồn tại.',409);
            throw $e;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
        return $this->findById($id) ?? throw new RuntimeException('Room create failed');
    }

    public function update(string $id, array $input): array
    {
        $data=validate_room_input($input);$this->pdo->beginTransaction();
        try {
            $lock=$this->pdo->prepare('SELECT id FROM rooms WHERE id=? FOR UPDATE');$lock->execute([$id]);
            if(!$lock->fetch())throw new BookingException('Không tìm thấy phòng.',404);
            $stmt=$this->pdo->prepare('UPDATE rooms SET name=?,slug=?,price=?,max_guests=?,is_active=?,subtitle=?,description=?,tag=?,status=?,bedrooms=?,bathrooms=?,sort_order=? WHERE id=?');
            $stmt->execute([$data['name'],$data['slug'],$this->minimumPrice($data['packages']),$data['maxGuests'],$data['status']==='ACTIVE'?1:0,$data['subtitle'],$data['description'],'',$data['status'],$data['bedrooms'],$data['bathrooms'],$data['sortOrder'],$id]);
            $this->replaceTags($id,$data['tags']);$this->replacePackages($id,$data['packages']);$this->pdo->commit();
        } catch (PDOException $e) {
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            if((string)$e->getCode()==='23000')throw new BookingException('Slug phòng đã tồn tại.',409);throw $e;
        } catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $this->findById($id)??throw new RuntimeException('Room update failed');
    }

    public function delete(string $id): array
    {
        $this->pdo->beginTransaction();
        try {
            $lock=$this->pdo->prepare('SELECT id FROM rooms WHERE id=? FOR UPDATE');$lock->execute([$id]);if(!$lock->fetch())throw new BookingException('Không tìm thấy phòng.',404);
            $count=$this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE room_id=?');$count->execute([$id]);
            if(!room_deletion_allowed((int)$count->fetchColumn()))throw new BookingException('Phòng đã có đơn đặt. Hãy chuyển phòng sang trạng thái Ẩn.',409);
            $paths=$this->pdo->prepare('SELECT path FROM room_images WHERE room_id=?');$paths->execute([$id]);$files=$paths->fetchAll(PDO::FETCH_COLUMN);
            $this->pdo->prepare('DELETE FROM rooms WHERE id=?')->execute([$id]);$this->pdo->commit();return $files;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function load(string $where, array $params=[]): array
    {
        $stmt=$this->pdo->prepare('SELECT id,name,slug,subtitle,description,tag,status,max_guests,bedrooms,bathrooms,sort_order,created_at,updated_at FROM rooms WHERE '.$where.' ORDER BY sort_order,created_at');$stmt->execute($params);$rows=$stmt->fetchAll();
        if(!$rows)return [];$ids=array_column($rows,'id');$marks=implode(',',array_fill(0,count($ids),'?'));
        $child=function(string $sql)use($ids,$marks){$stmt=$this->pdo->prepare(str_replace(':ids',$marks,$sql));$stmt->execute($ids);return $stmt->fetchAll();};
        return hydrate_room_rows($rows,
            $child('SELECT id,room_id,path,caption,is_cover,sort_order FROM room_images WHERE room_id IN (:ids) ORDER BY sort_order,created_at'),
            $child('SELECT id,room_id,label,sort_order FROM room_tags WHERE room_id IN (:ids) ORDER BY sort_order'),
            $child('SELECT id,room_id,name,timing_mode,duration_minutes,check_in_time,check_out_time,price,is_enabled,sort_order FROM room_packages WHERE room_id IN (:ids) ORDER BY sort_order,created_at'));
    }

    private function replaceTags(string $roomId,array $tags):void
    {
        $this->pdo->prepare('DELETE FROM room_tags WHERE room_id=?')->execute([$roomId]);$stmt=$this->pdo->prepare('INSERT INTO room_tags(id,room_id,label,normalized_label,sort_order) VALUES(?,?,?,?,?)');
        foreach($tags as $index=>$tag)$stmt->execute([uuid(),$roomId,$tag,mb_strtolower($tag,'UTF-8'),($index+1)*10]);
    }

    private function replacePackages(string $roomId,array $packages):void
    {
        $existing=$this->pdo->prepare('SELECT id FROM room_packages WHERE room_id=?');$existing->execute([$roomId]);$old=$existing->fetchAll(PDO::FETCH_COLUMN);$kept=[];
        $stmt=$this->pdo->prepare("INSERT INTO room_packages(id,room_id,name,timing_mode,duration_minutes,check_in_time,check_out_time,price,is_enabled,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),timing_mode=VALUES(timing_mode),duration_minutes=VALUES(duration_minutes),check_in_time=VALUES(check_in_time),check_out_time=VALUES(check_out_time),price=VALUES(price),is_enabled=VALUES(is_enabled),sort_order=VALUES(sort_order)");
        foreach($packages as $package){$id=select_package_id($package['id'],$old);$kept[]=$id;$stmt->execute([$id,$roomId,$package['name'],$package['mode'],$package['durationMinutes'],$package['checkInTime'],$package['checkOutTime'],$package['price'],$package['enabled']?1:0,$package['sortOrder']]);}
        foreach(array_diff($old,$kept) as $id){$used=$this->pdo->prepare('SELECT COUNT(*) FROM bookings WHERE package_id=?');$used->execute([$id]);if((int)$used->fetchColumn()>0)$this->pdo->prepare('UPDATE room_packages SET is_enabled=0 WHERE id=?')->execute([$id]);else $this->pdo->prepare('DELETE FROM room_packages WHERE id=?')->execute([$id]);}
    }

    private function minimumPrice(array $packages): int
    {
        $prices=array_map(fn($p)=>(int)$p['price'],array_filter($packages,fn($p)=>$p['enabled']));return $prices?min($prices):0;
    }
}

function room_repository(): RoomRepository { static $repository; return $repository ??= new RoomRepository(db()); }
