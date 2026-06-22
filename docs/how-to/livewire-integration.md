# Livewire — bad vs good

This page expands the Livewire integration guidance from the [README](../README.md). Keep public component state token-clean, and keep `GazeSession` values method-scoped.

Livewire serializes public component properties to the client between updates. Putting raw PII or the session blob on a public property leaks both surfaces. Here is the same flow shown wrong-then-right.

**~~DO NOT~~ — leaks PII to the wire and persists the blob across updates:**

```php
class DraftReply extends Component
{
    public string $rawEmailBody = '';   // raw PII serialized to client on every update
    public ?GazeSession $session = null; // blob persisted across Livewire round-trips
    public string $reply = '';

    public function generate(Gaze $gaze, Llm $llm): void
    {
        $this->session = $gaze->clean($this->rawEmailBody);
        $this->reply = $llm->complete($this->session->cleanText);
    }

    public function mount(Gaze $gaze): void
    {
        // worse: restoring on mount echoes restored PII back into a public property
        $this->reply = $gaze->restore($this->session, $this->reply);
    }
}
```

**Good — clean/restore inside one action, nothing PII-shaped on the component:**

```php
class DraftReply extends Component
{
    public string $reply = '';

    public function generate(string $rawEmailBody, Gaze $gaze, Llm $llm): void
    {
        $session = $gaze->clean($rawEmailBody);          // method-scoped
        $tokenized = $llm->complete($session->cleanText); // model sees tokens only
        $this->reply = $gaze->restore($session, $tokenized); // restored once, returned
        // $session goes out of scope here; nothing PII-shaped survives the action
    }

    public function render()
    {
        return view('livewire.draft-reply');
    }
}
```

Rules of thumb:

- Raw PII enters as a method argument or comes from an Eloquent relation resolved inside the action — never as a public property.
- `GazeSession` lives on the stack inside the action. It does not become a `public ?GazeSession`.
- Restored output is rendered once. If the user re-edits and re-submits, you call `clean()` + `restore()` again with a fresh blob.
