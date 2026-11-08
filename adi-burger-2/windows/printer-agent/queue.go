package main

// Chop etish navbati: diskda saqlanadi (kompyuter o'chsa ham yo'qolmaydi),
// bitta ishchi ketma-ket chop etadi — ikki chek hech qachon aralashmaydi.

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sort"
	"sync"
	"time"
)

const (
	StPending   = "pending"   // Kutilmoqda
	StSending   = "sending"   // Printerga yuborilmoqda
	StSent      = "sent"      // Yuborildi
	StError     = "error"     // Xato (printerga hech narsa ketmagan)
	StUnknown   = "unknown"   // Natija noma'lum (yarim yuborilgan bo'lishi mumkin)
	StReprinted = "reprinted" // Qayta chiqarildi (o'rniga yangi chek yaratilgan)


	keepJobs        = 400
)

// autoRetryWindow: printer javob bermasa shu vaqt davomida o'zi qayta urinadi
// (printerga hech narsa ketmagan bo'lsa — ikki marta chiqish xavfi yo'q).
var autoRetryWindow = 2 * time.Minute


type Job struct {
	ID        string    `json:"id"`
	Kind      string    `json:"kind"`
	OrderRef  string    `json:"order_ref,omitempty"`
	Title     string    `json:"title,omitempty"`
	Doc       Doc       `json:"doc"`
	Copies    int       `json:"copies"`
	Status    string    `json:"status"`
	Attempts  int       `json:"attempts"`
	Error     string    `json:"error,omitempty"`
	ReprintOf string    `json:"reprint_of,omitempty"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
	NextTry   time.Time `json:"next_try,omitempty"`
	SentMs    int64     `json:"sent_ms,omitempty"`
}

type Queue struct {
	mu    sync.Mutex
	jobs  map[string]*Job
	file  string
	wake  chan struct{}
	cfg   func() Config
	state PrinterState
}

func NewQueue(dir string, cfg func() Config) *Queue {
	q := &Queue{jobs: map[string]*Job{}, file: filepath.Join(dir, "jobs.json"), wake: make(chan struct{}, 1), cfg: cfg}
	if b, err := os.ReadFile(q.file); err == nil {
		var list []*Job
		if json.Unmarshal(b, &list) == nil {
			for _, j := range list {
				// Kompyuter "yuborilmoqda" holatida o'chgan bo'lsa — natija noma'lum (ikki marta chiqmasin)
				if j.Status == StSending {
					j.Status = StUnknown
					j.Error = "Yuborish paytida dastur to'xtagan. Chek chiqqanini qog'ozdan tekshiring."
				}
				q.jobs[j.ID] = j
			}
		}
	}
	return q
}

func (q *Queue) saveLocked() {
	list := q.sortedLocked()
	if len(list) > keepJobs {
		// Eng eskilari (faqat yakunlanganlari) o'chiriladi
		trimmed := list[:0]
		drop := len(list) - keepJobs
		for _, j := range list {
			if drop > 0 && (j.Status == StSent || j.Status == StReprinted) {
				delete(q.jobs, j.ID)
				drop--
				continue
			}
			trimmed = append(trimmed, j)
		}
		list = trimmed
	}
	b, _ := json.MarshalIndent(list, "", " ")
	tmp := q.file + ".tmp"
	if os.WriteFile(tmp, b, 0o600) == nil {
		_ = os.Rename(tmp, q.file)
	}
}

func (q *Queue) sortedLocked() []*Job {
	list := make([]*Job, 0, len(q.jobs))
	for _, j := range q.jobs {
		list = append(list, j)
	}
	sort.Slice(list, func(a, b int) bool { return list[a].CreatedAt.Before(list[b].CreatedAt) })
	return list
}

func (q *Queue) kick() {
	select {
	case q.wake <- struct{}{}:
	default:
	}
}

var ErrBadJob = errors.New("bad job")

// Add: yangi chek. Shu ID li chek allaqachon bo'lsa — qaytadan qo'shilmaydi (idempotent).
func (q *Queue) Add(j *Job) (*Job, bool) {
	q.mu.Lock()
	defer q.mu.Unlock()
	if ex, ok := q.jobs[j.ID]; ok {
		c := *ex
		return &c, false
	}
	now := time.Now()
	j.Status = StPending
	j.CreatedAt, j.UpdatedAt = now, now
	if j.Copies < 1 || j.Copies > 5 {
		j.Copies = 1
	}
	q.jobs[j.ID] = j
	q.saveLocked()
	q.kick()
	c := *j
	return &c, true
}

func (q *Queue) Get(id string) *Job {
	q.mu.Lock()
	defer q.mu.Unlock()
	if j, ok := q.jobs[id]; ok {
		c := *j
		return &c
	}
	return nil
}

func (q *Queue) List(limit int) []Job {
	q.mu.Lock()
	defer q.mu.Unlock()
	list := q.sortedLocked()
	out := []Job{}
	for i := len(list) - 1; i >= 0 && len(out) < limit; i-- {
		c := *list[i]
		c.Doc = Doc{} // ro'yxatda chek matni shart emas
		out = append(out, c)
	}
	return out
}

func (q *Queue) Counts() map[string]int {
	q.mu.Lock()
	defer q.mu.Unlock()
	m := map[string]int{StPending: 0, StSending: 0, StError: 0, StUnknown: 0, StSent: 0}
	for _, j := range q.jobs {
		m[j.Status]++
	}
	return m
}

// Retry: faqat "Xato" (printerga hech narsa ketmagan) chekni qayta navbatga qo'yadi.
func (q *Queue) Retry(id string) error {
	q.mu.Lock()
	defer q.mu.Unlock()
	j, ok := q.jobs[id]
	if !ok {
		return errors.New("chek topilmadi")
	}
	if j.Status != StError {
		return errors.New("faqat xato holatidagi chekni qayta yuborish mumkin; noma'lum holatdagisi uchun \"Qayta chiqarish\" ni ishlating")
	}
	j.Status, j.Error, j.NextTry, j.UpdatedAt = StPending, "", time.Time{}, time.Now()
	j.Attempts = 0
	q.saveLocked()
	q.kick()
	return nil
}

// RetryErrors: printer tiklangandan keyin barcha "Xato" cheklarni qayta yuborish.
func (q *Queue) RetryErrors() int {
	q.mu.Lock()
	n := 0
	for _, j := range q.jobs {
		if j.Status == StError {
			j.Status, j.Error, j.NextTry, j.Attempts, j.UpdatedAt = StPending, "", time.Time{}, 0, time.Now()
			n++
		}
	}
	if n > 0 {
		q.saveLocked()
	}
	q.mu.Unlock()
	q.kick()
	return n
}

// Reprint: chekni qayta chiqarish (kassir ataylab bosadi). Yangi ID, eski chek "Qayta chiqarildi".
func (q *Queue) Reprint(id, newID string) (*Job, error) {
	q.mu.Lock()
	j, ok := q.jobs[id]
	if !ok {
		q.mu.Unlock()
		return nil, errors.New("chek topilmadi")
	}
	if j.Status == StPending || j.Status == StSending {
		q.mu.Unlock()
		return nil, errors.New("chek hali navbatda — kuting")
	}
	n := &Job{ID: newID, Kind: j.Kind, OrderRef: j.OrderRef, Title: j.Title, Doc: j.Doc, Copies: 1, ReprintOf: j.ID}
	j.Status, j.UpdatedAt = StReprinted, time.Now()
	q.mu.Unlock()
	added, _ := q.Add(n)
	return added, nil
}

func (q *Queue) next() *Job {
	q.mu.Lock()
	defer q.mu.Unlock()
	for _, j := range q.sortedLocked() {
		if j.Status == StPending {
			// Navbat tartibi buzilmasin: birinchi kutilayotgan chek tayyor bo'lmasa, keyingilar ham kutadi
			if time.Now().Before(j.NextTry) {
				return nil
			}
			j.Status, j.UpdatedAt = StSending, time.Now()
			j.Attempts++
			q.saveLocked()
			c := *j
			return &c
		}
	}
	return nil
}

func (q *Queue) finish(id string, err error, ms int64) {
	q.mu.Lock()
	defer q.mu.Unlock()
	j, ok := q.jobs[id]
	if !ok {
		return
	}
	j.UpdatedAt = time.Now()
	if err == nil {
		j.Status, j.Error, j.SentMs = StSent, "", ms
		q.state.OK, q.state.Error = true, ""
	} else {
		var se *SendError
		written := errors.As(err, &se) && se.Written
		j.Error = err.Error()
		q.state.OK, q.state.Error = false, err.Error()
		switch {
		case written:
			j.Status = StUnknown // avtomatik qayta yuborilmaydi
		case time.Since(j.CreatedAt) < autoRetryWindow:
			j.Status = StPending
			j.NextTry = time.Now().Add(3 * time.Second)
		default:
			j.Status = StError
		}
	}
	q.state.CheckedAt = time.Now().Format(time.RFC3339)
	q.saveLocked()
}

// Run: yagona ishchi. Cheklar qat'iy navbat bilan, bittadan chop etiladi.
func (q *Queue) Run() {
	tick := time.NewTicker(1 * time.Second)
	defer tick.Stop()
	for {
		for {
			j := q.next()
			if j == nil {
				break
			}
			c := q.cfg().Printer
			data := Render(j.Doc, c.Width, c.Cut)
			t := time.Now()
			var err error
			for i := 0; i < j.Copies && err == nil; i++ {
				err = printerSend(c, data)
				if err != nil && i > 0 {
					// birinchi nusxa chiqqan — natija noma'lum
					var se *SendError
					if errors.As(err, &se) {
						se.Written = true
					}
				}
			}
			logf("chek %s (%s %s) → %v", j.ID, j.Kind, j.OrderRef, errStr(err))
			q.finish(j.ID, err, time.Since(t).Milliseconds())
		}
		select {
		case <-q.wake:
		case <-tick.C:
		}
	}
}

func (q *Queue) SetState(s PrinterState) {
	q.mu.Lock()
	q.state = s
	q.mu.Unlock()
}

func (q *Queue) State() PrinterState {
	q.mu.Lock()
	defer q.mu.Unlock()
	return q.state
}

func errStr(err error) string {
	if err == nil {
		return "OK"
	}
	return err.Error()
}
